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

/// Manages active sessions and per-IP login rate limiting / lockout
#[derive(Clone)]
pub struct AuthManager {
    sessions: Arc<RwLock<HashMap<String, Instant>>>, // token -> expires_at
    ip_attempts: Arc<RwLock<HashMap<String, IpAttempt>>>,
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

        // If no password file exists, generate a physical setup code
        if !password_file.exists() {
            let code = format!("{:06}", (Uuid::new_v4().as_u128() % 900_000) + 100_000);
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
            password_file,
            setup_code: Arc::new(RwLock::new(setup_code)),
            stream_secret,
        }
    }

    /// Whether the device has an initialized admin password
    pub fn is_initialized(&self) -> bool {
        self.password_file.exists()
    }

    /// Complete first-time setup using the physical verification code
    pub fn setup_first_password(&self, code: &str, new_password: &str) -> Result<(), &'static str> {
        let current_code = {
            let guard = self.setup_code.read().map_err(|_| "Lock error")?;
            guard.clone()
        };

        match current_code {
            Some(expected) if expected == code.trim() => {
                if new_password.trim().len() < 6 {
                    return Err("Password must be at least 6 characters");
                }
                self.save_password(new_password)?;
                let mut guard = self.setup_code.write().map_err(|_| "Lock error")?;
                *guard = None;
                info!("Admin password successfully initialized via setup code.");
                Ok(())
            }
            _ => Err("Invalid or expired setup verification code"),
        }
    }

    /// Save new password hashed with Argon2
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

        // Invalidate all existing sessions on password change
        if let Ok(mut sessions) = self.sessions.write() {
            sessions.clear();
        }

        Ok(())
    }

    /// Verify password with per-IP rate-limiting, exponential delay, and lockout
    pub fn verify_login(&self, ip: &str, password: &str) -> Result<String, (StatusCode, String)> {
        let now = Instant::now();

        // 1. Check IP lockout status
        {
            let attempts = self.ip_attempts.read().map_err(|_| {
                (StatusCode::INTERNAL_SERVER_ERROR, "Lock error".to_string())
            })?;
            if let Some(attempt) = attempts.get(ip) {
                if let Some(lockout) = attempt.lockout_until {
                    if now < lockout {
                        let remaining = (lockout - now).as_secs();
                        return Err((
                            StatusCode::TOO_MANY_REQUESTS,
                            format!("Too many failed attempts. Try again in {} seconds.", remaining),
                        ));
                    }
                }
            }
        }

        // 2. Read stored Argon2 hash
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

        // 3. Constant-time Argon2 verification
        let argon2 = Argon2::default();
        let valid = argon2.verify_password(password.as_bytes(), &parsed_hash).is_ok();

        // 4. Update IP attempt tracker
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

            // Create session with 1-hour TTL
            let token = Uuid::new_v4().to_string();
            if let Ok(mut sessions) = self.sessions.write() {
                sessions.insert(token.clone(), now + SESSION_TTL);
            }
            info!("Admin control session authenticated successfully from IP: {}", ip);
            Ok(token)
        } else {
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
                warn!(
                    "Failed login from {}. Count: {}. Delay/Lockout: {}s",
                    ip, entry.failed_count, delay_secs
                );
            }

            Err((
                StatusCode::UNAUTHORIZED,
                "Invalid password".to_string(),
            ))
        }
    }

    /// Check if request has a valid session (Bearer token or cookie)
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
            // Remove expired sessions
            sessions.retain(|_, &mut exp| exp > now);

            if let Some(exp) = sessions.get_mut(&token) {
                // Refresh sliding TTL
                *exp = now + SESSION_TTL;
                true
            } else {
                false
            }
        } else {
            false
        }
    }

    /// Invalidate a session token
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

    /// Verify short-lived HMAC signed stream token for read-only routes (/stream, /snapshot.jpg, /events)
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

        // Token must not be expired
        if current_ts > expires_ts {
            return false;
        }

        // Expected message: "stream:<device_id>:<expires>"
        let message = format!("stream:{}:{}", device_id, expires_ts);
        let mut mac = match HmacSha256::new_from_slice(self.stream_secret.as_bytes()) {
            Ok(m) => m,
            Err(_) => return false,
        };
        mac.update(message.as_bytes());

        let expected_bytes = mac.finalize().into_bytes();
        let expected_hex = hex_encode(&expected_bytes);

        // Constant-time compare
        constant_time_eq_str(token, &expected_hex)
    }

    /// CSRF Origin verification on POST / PUT / DELETE requests
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

        // Standard direct curl or non-browser client without origin is accepted
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

        let auth = AuthManager::new(&temp_dir, "test_secret_123".to_string());
        assert!(!auth.is_initialized());

        let code = auth.setup_code.read().unwrap().clone().expect("Setup code should exist");

        // Invalid code should fail
        assert!(auth.setup_first_password("999999", "new_secure_pwd_123").is_err());
        assert!(!auth.is_initialized());

        // Correct code initializes password
        assert!(auth.setup_first_password(&code, "new_secure_pwd_123").is_ok());
        assert!(auth.is_initialized());

        // Login with correct password
        let token = auth.verify_login("192.168.1.50", "new_secure_pwd_123").expect("Login should succeed");
        assert!(!token.is_empty());

        // Login with wrong password
        let err = auth.verify_login("192.168.1.50", "wrong_password");
        assert!(err.is_err());

        let _ = std::fs::remove_dir_all(&temp_dir);
    }

    #[test]
    fn test_per_ip_lockout_delay() {
        let temp_dir = std::env::temp_dir().join(format!("lockout_test_{}", Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);

        let auth = AuthManager::new(&temp_dir, "test_secret_123".to_string());
        let _ = auth.save_password("correct_password_123");

        let ip = "192.168.1.99";

        // 4 failed attempts shouldn't lock out completely yet
        for _ in 0..4 {
            let res = auth.verify_login(ip, "wrong");
            assert!(res.is_err());
        }

        // 5th failed attempt triggers lockout
        let res5 = auth.verify_login(ip, "wrong");
        assert!(res5.is_err());

        // Immediate subsequent attempt should get 429 Too Many Requests
        let res6 = auth.verify_login(ip, "correct_password_123");
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

        // Generate expected HMAC
        let message = format!("stream:{}:{}", device_id, expires_str);
        let mut mac = HmacSha256::new_from_slice("device_secret_abc123".as_bytes()).unwrap();
        mac.update(message.as_bytes());
        let valid_token = hex_encode(&mac.finalize().into_bytes());

        // Valid token
        assert!(auth.verify_stream_token(Some(&valid_token), Some(&expires_str), device_id));

        // Tampered token
        assert!(!auth.verify_stream_token(Some("tampered_token_xyz"), Some(&expires_str), device_id));

        // Expired timestamp
        let past_ts = (SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() - 10).to_string();
        assert!(!auth.verify_stream_token(Some(&valid_token), Some(&past_ts), device_id));

        // Wrong device ID
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

        // Malicious third-party origin
        headers.insert("origin", "http://evil-attacker.com".parse().unwrap());
        assert!(!auth.verify_origin(&headers));
    }
}
