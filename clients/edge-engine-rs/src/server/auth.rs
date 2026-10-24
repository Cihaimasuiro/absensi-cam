use argon2::{
    password_hash::{rand_core::OsRng, PasswordHash, PasswordHasher, PasswordVerifier, SaltString},
    Argon2,
};
use axum::http::{header, HeaderMap, StatusCode};
use hmac::{Hmac, Mac};
use sha2::Sha256;
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::sync::{Arc, RwLock};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use tracing::{info, warn};
use uuid::Uuid;

type HmacSha256 = Hmac<Sha256>;

const SESSION_TTL: Duration = Duration::from_secs(3600); // 1 hour sliding TTL

/// Mengelola sesi aktif serta pembatasan laju / lockout login per-IP
#[derive(Clone)]
pub struct AuthManager {
    sessions: Arc<RwLock<HashMap<String, Instant>>>, // token -> expires_at
    ip_attempts: Arc<RwLock<HashMap<String, IpAttempt>>>,
    setup_attempts: Arc<RwLock<HashMap<String, IpAttempt>>>,
    password_file: PathBuf,
    setup_code: Arc<RwLock<Option<String>>>,
    stream_secret: String,
}

#[derive(Clone, Debug)]
struct IpAttempt {
    failed_count: u32,
    lockout_until: Option<Instant>,
}

impl AuthManager {
    pub fn new(db_dir: &Path, stream_secret: String) -> Self {
        let password_file = db_dir.join("panel_admin.argon2");
        let mut setup_code = None;

        // Jika file password belum ada, buat kode setup fisik
        if !password_file.exists() {
            let code = format!("{:06}", (Uuid::new_v4().as_u128() % 900_000) + 100_000);
            let code_file = db_dir.join("setup_code.txt");
            let _ = std::fs::write(&code_file, &code);

            println!("==================================================================");
            println!(" [FIRST-TIME SETUP] Physical Setup Verification Code: [ {} ]", code);
            println!(" (Saved to {})", code_file.display());
            println!(" Open http://127.0.0.1:5000/control to set your admin password.");
            println!("==================================================================");

            warn!("==================================================================");
            warn!("FIRST-TIME SETUP: No admin password configured!");
            warn!("Physical Setup Verification Code: [ {} ]", code);
            warn!("Open http://<device-ip>:5000 to set your admin password.");
            warn!("==================================================================");
            setup_code = Some(code);
        }

        Self {
            sessions: Arc::new(RwLock::new(HashMap::new())),
            ip_attempts: Arc::new(RwLock::new(HashMap::new())),
            setup_attempts: Arc::new(RwLock::new(HashMap::new())),
            password_file,
            setup_code: Arc::new(RwLock::new(setup_code)),
            stream_secret,
        }
    }

    /// Menandakan apakah perangkat sudah memiliki password admin yang terinisialisasi
    pub fn is_initialized(&self) -> bool {
        self.password_file.exists()
    }

    /// Selesaikan setup pertama kali memakai kode verifikasi fisik dengan rate-limit per-IP dan perbandingan constant-time
    pub fn setup_first_password(&self, ip: &str, code: &str, new_password: &str) -> Result<(), &'static str> {
        let now = Instant::now();

        // 1. Periksa status lockout dan catat percobaan secara atomik
        {
            let mut attempts = self.setup_attempts.write().map_err(|_| "Lock error")?;
            let entry = attempts.entry(ip.to_string()).or_insert(IpAttempt {
                failed_count: 0,
                lockout_until: None,
            });

            if let Some(lockout) = entry.lockout_until {
                if now < lockout {
                    return Err("Too many failed setup attempts. Try again in 15 minutes.");
                }
            }

            // Catat percobaan terlebih dahulu untuk mencegah race condition tebakan paralel
            entry.failed_count += 1;
            if entry.failed_count >= 5 {
                entry.lockout_until = Some(now + Duration::from_secs(900));
                warn!("Physical setup lockout triggered for IP {} after 5 failed attempts.", ip);
            }
        }

        let current_code = {
            let guard = self.setup_code.read().map_err(|_| "Lock error")?;
            guard.clone()
        };

        let is_valid = match current_code {
            Some(ref expected) => constant_time_eq_str(expected.trim(), code.trim()),
            None => false,
        };

        if is_valid {
            if new_password.trim().len() < 8 {
                return Err("Password must be at least 8 characters");
            }
            self.save_password(new_password)?;
            let mut guard = self.setup_code.write().map_err(|_| "Lock error")?;
            *guard = None;
            let code_file = self.password_file.parent().unwrap_or(Path::new(".")).join("setup_code.txt");
            let _ = std::fs::remove_file(code_file);
            if let Ok(mut attempts) = self.setup_attempts.write() {
                attempts.remove(ip);
            }
            info!("Admin password successfully initialized via setup code from IP: {}.", ip);
            Ok(())
        } else {
            Err("Invalid or expired setup verification code")
        }
    }

    /// Simpan password baru yang di-hash dengan Argon2
    pub fn save_password(&self, password: &str) -> Result<(), &'static str> {
        let salt = SaltString::generate(&mut OsRng);
        let argon2 = Argon2::default();
        let password_hash = argon2
            .hash_password(password.as_bytes(), &salt)
            .map_err(|_| "Argon2 hashing failed")?
            .to_string();

        if let Some(parent) = self.password_file.parent() {
            let _ = std::fs::create_dir_all(parent);
        }

        std::fs::write(&self.password_file, password_hash)
            .map_err(|_| "Failed to write password file")?;

        // Batalkan semua sesi yang ada setiap kali password berubah
        if let Ok(mut sessions) = self.sessions.write() {
            sessions.clear();
        }

        Ok(())
    }

    /// Verifikasi password dengan rate-limit per-IP, jeda eksponensial, dan lockout
    pub fn verify_login(&self, ip: &str, password: &str) -> Result<String, (StatusCode, String)> {
        let now = Instant::now();

        // 1. Periksa status lockout IP dan catat percobaan secara atomik
        {
            let mut attempts = self.ip_attempts.write().map_err(|_| {
                (StatusCode::INTERNAL_SERVER_ERROR, "Lock error".to_string())
            })?;
            let entry = attempts.entry(ip.to_string()).or_insert(IpAttempt {
                failed_count: 0,
                lockout_until: None,
            });

            if let Some(lockout) = entry.lockout_until {
                if now < lockout {
                    let remaining = (lockout - now).as_secs();
                    return Err((
                        StatusCode::TOO_MANY_REQUESTS,
                        format!("Too many failed attempts. Try again in {} seconds.", remaining),
                    ));
                }
            }

            // Catat percobaan awal secara atomik untuk mencegah parallel guessing race
            entry.failed_count += 1;
            let delay_secs = match entry.failed_count {
                1 | 2 => 0,
                3 => 2,
                4 => 5,
                5 => 30,
                _ => 300, // 5 minutes lockout after 6+ failures
            };
            if delay_secs > 0 {
                entry.lockout_until = Some(now + Duration::from_secs(delay_secs));
            }
        }

        // 2. Baca hash Argon2 yang tersimpan
        let hash_str = match std::fs::read_to_string(&self.password_file) {
            Ok(h) => h,
            Err(_) => {
                return Err((
                    StatusCode::PRECONDITION_REQUIRED,
                    "Admin password not initialized. Please complete setup.".to_string(),
                ));
            }
        };

        let parsed_hash = match PasswordHash::new(&hash_str) {
            Ok(p) => p,
            Err(_) => {
                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "Invalid password hash format".to_string(),
                ));
            }
        };

        // 3. Verifikasi Argon2 dengan waktu konstan
        let argon2 = Argon2::default();
        let valid = argon2.verify_password(password.as_bytes(), &parsed_hash).is_ok();

        // 4. Perbarui status percobaan
        let mut attempts = self.ip_attempts.write().map_err(|_| {
            (StatusCode::INTERNAL_SERVER_ERROR, "Lock error".to_string())
        })?;
        let entry = attempts.entry(ip.to_string()).or_insert(IpAttempt {
            failed_count: 0,
            lockout_until: None,
        });

        if valid {
            entry.failed_count = 0;
            entry.lockout_until = None;

            // Buat sesi dengan TTL 1 jam
            let token = Uuid::new_v4().to_string();
            if let Ok(mut sessions) = self.sessions.write() {
                sessions.insert(token.clone(), now + SESSION_TTL);
            }
            info!("Admin control session authenticated successfully from IP: {}", ip);
            Ok(token)
        } else {
            warn!(
                "Failed login from {}. Count: {}.",
                ip, entry.failed_count
            );
            Err((
                StatusCode::UNAUTHORIZED,
                "Invalid password".to_string(),
            ))
        }
    }

    /// Periksa apakah request memiliki sesi valid (Bearer token atau cookie)
    pub fn check_session(&self, headers: &HeaderMap) -> bool {
        let token = if let Some(auth_val) = headers.get(header::AUTHORIZATION).and_then(|v| v.to_str().ok()) {
            if let Some(stripped) = auth_val.strip_prefix("Bearer ") {
                stripped.trim().to_string()
            } else {
                auth_val.trim().to_string()
            }
        } else if let Some(cookie_val) = headers.get(header::COOKIE).and_then(|v| v.to_str().ok()) {
            extract_cookie_val(cookie_val, "control_session").unwrap_or_default()
        } else {
            return false;
        };

        if token.is_empty() {
            return false;
        }

        let now = Instant::now();
        if let Ok(mut sessions) = self.sessions.write() {
            // Hapus sesi yang sudah kedaluwarsa
            sessions.retain(|_, &mut exp| exp > now);

            if let Some(exp) = sessions.get_mut(&token) {
                // Segarkan TTL geser (sliding)
                *exp = now + SESSION_TTL;
                true
            } else {
                false
            }
        } else {
            false
        }
    }

    /// Batalkan token sesi
    pub fn logout(&self, headers: &HeaderMap) {
        let token = if let Some(auth_val) = headers.get(header::AUTHORIZATION).and_then(|v| v.to_str().ok()) {
            auth_val.strip_prefix("Bearer ").unwrap_or(auth_val).trim().to_string()
        } else if let Some(cookie_val) = headers.get(header::COOKIE).and_then(|v| v.to_str().ok()) {
            extract_cookie_val(cookie_val, "control_session").unwrap_or_default()
        } else {
            return;
        };

        if let Ok(mut sessions) = self.sessions.write() {
            sessions.remove(&token);
        }
    }

    /// Verifikasi token stream bertanda tangan HMAC berumur pendek untuk route read-only (/stream, /snapshot.jpg, /events)
    pub fn verify_stream_token(&self, token: Option<&str>, expires_str: Option<&str>, device_id: &str) -> bool {
        let (token, expires_str) = match (token, expires_str) {
            (Some(t), Some(e)) if !t.is_empty() && !e.is_empty() => (t, e),
            _ => return false,
        };

        let expires_ts: u64 = match expires_str.parse() {
            Ok(ts) => ts,
            Err(_) => return false,
        };

        let current_ts = match SystemTime::now().duration_since(UNIX_EPOCH) {
            Ok(d) => d.as_secs(),
            Err(_) => return false,
        };

        // Token tidak boleh kedaluwarsa
        if current_ts > expires_ts {
            return false;
        }

        // Pesan yang diharapkan: "stream:<device_id>:<expires>"
        let message = format!("stream:{}:{}", device_id, expires_ts);
        let mut mac = match HmacSha256::new_from_slice(self.stream_secret.as_bytes()) {
            Ok(m) => m,
            Err(_) => return false,
        };
        mac.update(message.as_bytes());

        let expected_bytes = mac.finalize().into_bytes();
        let expected_hex = hex_encode(&expected_bytes);

        // Perbandingan dengan waktu konstan
        constant_time_eq_str(token, &expected_hex)
    }

    /// Verifikasi Origin CSRF pada request POST / PUT / DELETE
    pub fn verify_origin(&self, headers: &HeaderMap) -> bool {
        let host = match headers.get(header::HOST).and_then(|v| v.to_str().ok()) {
            Some(h) => h,
            None => return true, // Can't verify without Host header
        };

        if let Some(origin) = headers.get("origin").and_then(|v| v.to_str().ok()) {
            let stripped = origin
                .trim_start_matches("http://")
                .trim_start_matches("https://");
            return stripped == host || stripped.starts_with(&format!("{}:", host));
        }

        if let Some(referer) = headers.get(header::REFERER).and_then(|v| v.to_str().ok()) {
            let stripped = referer
                .trim_start_matches("http://")
                .trim_start_matches("https://");
            return stripped.starts_with(host);
        }

        // Klien langsung seperti curl atau non-browser tanpa origin tetap diterima
        true
    }
}

fn extract_cookie_val(cookie_header: &str, name: &str) -> Option<String> {
    for part in cookie_header.split(';') {
        let mut items = part.trim().splitn(2, '=');
        if let (Some(k), Some(v)) = (items.next(), items.next()) {
            if k == name {
                return Some(v.to_string());
            }
        }
    }
    None
}

fn hex_encode(bytes: &[u8]) -> String {
    let mut s = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        s.push_str(&format!("{:02x}", b));
    }
    s
}

fn constant_time_eq_str(a: &str, b: &str) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for (x, y) in a.bytes().zip(b.bytes()) {
        diff |= x ^ y;
    }
    diff == 0
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_first_time_setup_and_argon2_auth() {
        let temp_dir = std::env::temp_dir().join(format!("auth_test_{}", Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);

        let test_pwd = format!("pwd_{}", Uuid::new_v4());
        let wrong_pwd = format!("wrong_{}", Uuid::new_v4());

        let auth = AuthManager::new(&temp_dir, "test_secret_123".to_string());
        assert!(!auth.is_initialized());

        let code = auth.setup_code.read().unwrap().clone().expect("Setup code should exist");

        // Kode yang salah seharusnya gagal
        assert!(auth.setup_first_password("192.168.1.50", "999999", &test_pwd).is_err());
        assert!(!auth.is_initialized());

        // Kode yang benar menginisialisasi password
        assert!(auth.setup_first_password("192.168.1.50", &code, &test_pwd).is_ok());
        assert!(auth.is_initialized());

        // Login dengan password yang benar
        let token = auth.verify_login("192.168.1.50", &test_pwd).expect("Login should succeed");
        assert!(!token.is_empty());

        // Login dengan password yang salah
        let err = auth.verify_login("192.168.1.50", &wrong_pwd);
        assert!(err.is_err());

        let _ = std::fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_setup_code_lockout() {
        let temp_dir = std::env::temp_dir().join(format!("setup_lockout_test_{}", Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);
        let auth = AuthManager::new(&temp_dir, "test_secret_123".to_string());
        let ip = "10.0.0.5";
        let pwd = "StrongPassword123";

        // 4 percobaan gagal belum memicu lockout
        for _ in 0..4 {
            assert!(auth.setup_first_password(ip, "111111", pwd).is_err());
        }

        // Percobaan ke-5 gagal memicu lockout
        assert!(auth.setup_first_password(ip, "111111", pwd).is_err());

        // Percobaan ke-6 harus ditolak karena lockout
        let err = auth.setup_first_password(ip, "111111", pwd);
        assert!(err.is_err());
        assert!(err.unwrap_err().contains("Too many failed setup attempts"));

        let _ = std::fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_per_ip_lockout_delay() {
        let temp_dir = std::env::temp_dir().join(format!("lockout_test_{}", Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);

        let correct_pwd = format!("correct_{}", Uuid::new_v4());
        let wrong_pwd = format!("wrong_{}", Uuid::new_v4());

        let auth = AuthManager::new(&temp_dir, "test_secret_123".to_string());
        let _ = auth.save_password(&correct_pwd);

        let ip = "192.168.1.99";

        // 4 percobaan gagal seharusnya belum mengunci sepenuhnya
        for _ in 0..4 {
            let res = auth.verify_login(ip, &wrong_pwd);
            assert!(res.is_err());
        }

        // Percobaan gagal ke-5 memicu lockout
        let res5 = auth.verify_login(ip, &wrong_pwd);
        assert!(res5.is_err());

        // Percobaan berikutnya yang langsung dilakukan harus mendapat 429 Too Many Requests
        let res6 = auth.verify_login(ip, &correct_pwd);
        assert_eq!(res6.unwrap_err().0, StatusCode::TOO_MANY_REQUESTS);

        let _ = std::fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_hmac_stream_token() {
        let temp_dir = std::env::temp_dir().join(format!("hmac_test_{}", Uuid::new_v4()));
        let auth = AuthManager::new(&temp_dir, "device_secret_abc123".to_string());

        let device_id = "GATE-01";
        let future_ts = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() + 60;
        let expires_str = future_ts.to_string();

        // Buat HMAC yang diharapkan
        let message = format!("stream:{}:{}", device_id, expires_str);
        let mut mac = HmacSha256::new_from_slice("device_secret_abc123".as_bytes()).unwrap();
        mac.update(message.as_bytes());
        let valid_token = hex_encode(&mac.finalize().into_bytes());

        // Token valid
        assert!(auth.verify_stream_token(Some(&valid_token), Some(&expires_str), device_id));

        // Token yang dimanipulasi
        assert!(!auth.verify_stream_token(Some("tampered_token_xyz"), Some(&expires_str), device_id));

        // Timestamp yang sudah kedaluwarsa
        let past_ts = (SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() - 10).to_string();
        assert!(!auth.verify_stream_token(Some(&valid_token), Some(&past_ts), device_id));

        // ID perangkat yang salah
        assert!(!auth.verify_stream_token(Some(&valid_token), Some(&expires_str), "GATE-02"));
    }

    #[test]
    fn test_csrf_origin_check() {
        let temp_dir = std::env::temp_dir().join(format!("csrf_test_{}", Uuid::new_v4()));
        let auth = AuthManager::new(&temp_dir, "secret".to_string());

        let mut headers = HeaderMap::new();
        headers.insert(header::HOST, "192.168.1.10:5000".parse().unwrap());
        headers.insert("origin", "http://192.168.1.10:5000".parse().unwrap());

        assert!(auth.verify_origin(&headers));

        // Origin pihak ketiga yang berbahaya
        headers.insert("origin", "http://evil-attacker.com".parse().unwrap());
        assert!(!auth.verify_origin(&headers));
    }
}
