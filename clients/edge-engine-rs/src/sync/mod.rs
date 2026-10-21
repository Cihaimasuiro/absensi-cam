#![allow(clippy::cloned_ref_to_slice_refs)]
#![allow(clippy::needless_borrows_for_generic_args)]
#![allow(clippy::manual_is_multiple_of)]

use anyhow::{Context, Result};
use crate::core::{AttendanceBatchPayload, AttendancePayloadRecord, EdgeDb, HeartbeatPayload, OutboxRecord};
use reqwest::header::{HeaderMap, HeaderValue, ACCEPT, AUTHORIZATION, CONTENT_TYPE};
use serde::{Deserialize, Serialize};
use std::path::Path;
use std::time::Duration;
use tracing::{error, info, warn};

#[derive(Debug, Deserialize)]
pub struct RecordSyncResult {
    pub id: String,
    pub status: String, // "created" | "duplicate" | "rejected"
    #[serde(default)]
    pub reason: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct BatchSyncResponse {
    #[serde(default)]
    pub results: Vec<RecordSyncResult>,
}

pub struct SyncWorker {
    pub db: EdgeDb,
    pub api_url: String,
    pub api_token: String,
    pub client: reqwest::Client,
    pub embed_key: Option<Vec<u8>>,
    pub model_version: String,
    pub fw_version: String,
}

impl SyncWorker {
    pub fn new(
        db: EdgeDb,
        api_url: String,
        api_token: String,
        embed_key: Option<Vec<u8>>,
        model_version: String,
    ) -> Result<Self> {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(15))
            .build()?;

        Ok(Self {
            db,
            api_url: api_url.trim().trim_end_matches('/').to_string(),
            api_token: api_token.trim().to_string(),
            client,
            embed_key,
            model_version,
            fw_version: "v1.0.0-rust".to_string(),
        })
    }

    fn auth_headers(&self) -> HeaderMap {
        let mut headers = HeaderMap::new();
        headers.insert(CONTENT_TYPE, HeaderValue::from_static("application/json"));
        headers.insert(ACCEPT, HeaderValue::from_static("application/json"));
        if let Ok(val) = HeaderValue::from_str(&format!("Bearer {}", self.api_token)) {
            headers.insert(AUTHORIZATION, val);
        }
        headers
    }

    /// Loop sinkronisasi latar belakang mandiri
    /// Mengikuti spesifikasi PRD FR-E10, FR-E11, FR-E12:
    /// - Watchdog dedicated: ping tiap 20s independen dari jaringan
    /// - Outbox check: tiap 15s (FR-E10) dengan non-blocking backoff timestamp
    /// - Template & config sync: tiap 60s (FR-E11)
    /// - Heartbeat: tiap 60s (FR-E12)
    /// - Backoff schedule: 5s, 30s, 120s (2m), 600s (10m), 900s (15m)
    pub async fn run_loop(&self) {
        let mut watchdog_interval = tokio::time::interval(Duration::from_secs(20));
        let mut outbox_interval = tokio::time::interval(Duration::from_secs(15));
        let mut template_interval = tokio::time::interval(Duration::from_secs(60));
        let mut heartbeat_interval = tokio::time::interval(Duration::from_secs(60));

        const BACKOFF_SCHEDULE: [u64; 5] = [5, 30, 120, 600, 900];
        let mut failure_streak = 0usize;
        let mut next_outbox_attempt = tokio::time::Instant::now();

        info!(
            "SyncWorker daemon berjalan (Outbox: 15s, Templates: 60s, Heartbeat: 60s, Watchdog: 20s, Model: {})",
            self.model_version
        );

        loop {
            tokio::select! {
                // 1. Dedicated Watchdog Ping (TIDAK BOLEH TERHALANG OUTAGE JARINGAN)
                _ = watchdog_interval.tick() => {
                    notify_systemd("WATCHDOG=1\n");
                }

                // 2. Outbox Drain dengan Non-blocking Backoff (FR-E10)
                _ = outbox_interval.tick() => {
                    if tokio::time::Instant::now() < next_outbox_attempt {
                        // Masih dalam cooldown backoff; lewati tanpa memblokir task loop lain!
                        continue;
                    }

                    match self.push_outbox_to_laravel().await {
                        Ok((synced, rejected)) => {
                            if synced > 0 || rejected > 0 {
                                info!(
                                    "Outbox sync: {} disinkronkan, {} ditolak/dikarantina",
                                    synced, rejected
                                );
                            }
                            failure_streak = 0;
                        }
                        Err(e) => {
                            let backoff_secs = BACKOFF_SCHEDULE[failure_streak.min(BACKOFF_SCHEDULE.len() - 1)];
                            failure_streak += 1;
                            next_outbox_attempt = tokio::time::Instant::now() + Duration::from_secs(backoff_secs);
                            error!(
                                "Gagal push outbox absensi ke Laravel (kegagalan #{}): {:?}. Cooldown backoff aktif selama {}s (FR-E10)",
                                failure_streak, e, backoff_secs
                            );
                        }
                    }
                }

                // 3. Template & Config Delta Sync (FR-E11)
                _ = template_interval.tick() => {
                    if let Err(e) = self.pull_templates().await {
                        warn!("Gagal pull templates wajah: {:?}", e);
                    }
                }

                // 4. Heartbeat Telemetry (FR-E12)
                _ = heartbeat_interval.tick() => {
                    if let Err(e) = self.send_heartbeat().await {
                        warn!("Gagal mengirim heartbeat: {:?}", e);
                    }
                }
            }
        }
    }

    /// Push pending rows from outbox_attendance to Laravel /api/v1/attendance/batch
    /// - Parsir hasil per-record: hanya tandai status 'created' dan 'duplicate' sebagai synced.
    /// - Jika menerima HTTP 4xx (misal 422 Unprocessable Entity karena record beracun):
    ///   pecah batch menjadi pengiriman individual agar baris yang valid tetap tersinkron
    ///   dan baris yang korup dikarantina (synced = -1).
    pub async fn push_outbox_to_laravel(&self) -> Result<(usize, usize)> {
        let records = self.db.get_unsynced_attendance(50).await?;
        if records.is_empty() {
            return Ok((0, 0));
        }

        let payload_records: Vec<AttendancePayloadRecord> = records
            .iter()
            .map(|r| AttendancePayloadRecord {
                id: r.id.clone(),
                student_id: r.student_id.clone(),
                captured_at: r.captured_at.clone(),
                direction: r.direction.clone(),
                score: (r.score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                liveness_score: (r.liveness_score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                time_source: r.time_source.clone(),
            })
            .collect();

        let payload = AttendanceBatchPayload {
            records: payload_records,
        };

        let url = format!("{}/api/v1/attendance/batch", self.api_url);
        let resp = self
            .client
            .post(&url)
            .headers(self.auth_headers())
            .json(&payload)
            .send()
            .await
            .context("Network request failed while pushing attendance batch")?;

        let status = resp.status();
        if status.is_success() {
            let batch_resp: BatchSyncResponse = resp
                .json()
                .await
                .context("Failed to parse batch response json from Laravel")?;

            let mut synced_ids = Vec::new();
            let mut rejected_count = 0usize;

            for item in batch_resp.results {
                if item.status == "created" || item.status == "duplicate" {
                    synced_ids.push(item.id);
                } else if item.status == "rejected" {
                    rejected_count += 1;
                    warn!(
                        "Attendance record {} ditolak oleh Laravel: reason={:?}. Tetap disimpan di outbox.",
                        item.id, item.reason
                    );
                }
            }

            if !synced_ids.is_empty() {
                self.db.mark_as_synced(&synced_ids).await?;
            }

            Ok((synced_ids.len(), rejected_count))
        } else if status.is_client_error() {
            let body = resp.text().await.unwrap_or_default();
            warn!(
                "Laravel API mengembalikan HTTP {} untuk batch: {}. Memecah batch untuk mengisolasi record beracun...",
                status.as_u16(),
                body
            );
            self.push_records_individually(&records).await
        } else {
            let body = resp.text().await.unwrap_or_default();
            anyhow::bail!(
                "Laravel API returned error HTTP {}: {}",
                status.as_u16(),
                body
            );
        }
    }

    /// Fallback saat batch mendapat HTTP 4xx (misal 422):
    /// Mengirim tiap record satu per satu agar record valid tersinkron dan record beracun dikarantina.
    async fn push_records_individually(&self, records: &[OutboxRecord]) -> Result<(usize, usize)> {
        let mut synced_count = 0usize;
        let mut quarantined_count = 0usize;

        for r in records {
            let single_payload = AttendanceBatchPayload {
                records: vec![AttendancePayloadRecord {
                    id: r.id.clone(),
                    student_id: r.student_id.clone(),
                    captured_at: r.captured_at.clone(),
                    direction: r.direction.clone(),
                    score: (r.score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                    liveness_score: (r.liveness_score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                    time_source: r.time_source.clone(),
                }],
            };

            let url = format!("{}/api/v1/attendance/batch", self.api_url);
            let res = self
                .client
                .post(&url)
                .headers(self.auth_headers())
                .json(&single_payload)
                .send()
                .await;

            match res {
                Ok(resp) => {
                    let st = resp.status();
                    if st.is_success() {
                        self.db.mark_as_synced(&[r.id.clone()]).await?;
                        synced_count += 1;
                    } else if st.is_client_error() {
                        let body = resp.text().await.unwrap_or_default();
                        error!(
                            "Record {} permanen ditolak (HTTP {}): {}. Mengkarantina record (synced = -1) agar tidak memblokir antrean.",
                            r.id,
                            st.as_u16(),
                            body
                        );
                        self.db.quarantine_records(&[r.id.clone()]).await?;
                        quarantined_count += 1;
                    } else {
                        // 5xx server error, hentikan loop individual agar retry batch nanti
                        anyhow::bail!(
                            "Server error HTTP {} saat pengiriman individual record {}",
                            st.as_u16(),
                            r.id
                        );
                    }
                }
                Err(e) => {
                    anyhow::bail!(
                        "Network error saat pengiriman individual record {}: {:?}",
                        r.id,
                        e
                    );
                }
            }
        }

        Ok((synced_count, quarantined_count))
    }

    /// Pull updated face templates from Laravel /api/v1/templates
    /// Menolak template dengan model_version berbeda (FR-E11)
    pub async fn pull_templates(&self) -> Result<()> {
        let last_sync = self.db.get_last_template_sync_time().await?;
        let mut url = format!("{}/api/v1/templates", self.api_url);
        if let Some(cursor) = last_sync {
            url.push_str(&format!("?cursor={}", cursor));
        }

        let resp = self
            .client
            .get(&url)
            .headers(self.auth_headers())
            .send()
            .await
            .context("Network request failed while pulling templates")?;

        if resp.status() == reqwest::StatusCode::NOT_MODIFIED {
            return Ok(());
        }

        if !resp.status().is_success() {
            let status = resp.status();
            let body = resp.text().await.unwrap_or_default();
            anyhow::bail!("Failed to pull templates: HTTP {}: {}", status, body);
        }

        let json: serde_json::Value = resp.json().await?;
        let items = json.get("items").and_then(|v| v.as_array());
        let next_cursor = json.get("next_cursor").and_then(|v| v.as_i64());

        if let Some(items) = items {
            // Filter hanya template dengan model_version yang sesuai (FR-E11)
            let filtered_templates: Vec<serde_json::Value> = items
                .iter()
                .filter(|t| {
                    if let Some(ver) = t.get("model_version").and_then(|v| v.as_str()) {
                        if ver != self.model_version {
                            warn!(
                                "Menolak template student_id={:?}: model_version {} != {}",
                                t.get("student_id"),
                                ver,
                                self.model_version
                            );
                            return false;
                        }
                    }
                    true
                })
                .cloned()
                .collect();

            if !filtered_templates.is_empty() || next_cursor.is_some() {
                self.db.save_templates(&filtered_templates, next_cursor).await?;
                info!("Berhasil menyimpan {} update template wajah", filtered_templates.len());
            }
        }

        Ok(())
    }

    /// Kirim heartbeat telemetry ke Laravel /api/v1/devices/heartbeat
    pub async fn send_heartbeat(&self) -> Result<()> {
        let unsynced_len = self.db.get_unsynced_count().await.unwrap_or(0);
        let (tmpl_count, tmpl_hash) = match self.db.get_templates_hash().await {
            Ok((c, h)) => (Some(c), Some(h)),
            Err(e) => {
                warn!("Gagal menghitung hash templates: {:#}", e);
                (None, None)
            }
        };

        let quarantined_cnt = self.db.get_quarantined_templates_count().await.ok();

        let payload = HeartbeatPayload {
            fw_version: self.fw_version.clone(),
            model_version: self.model_version.clone(),
            cpu_temp: read_soc_temp(),
            ram_free_mb: read_free_ram_mb(),
            disk_free_mb: read_free_disk_mb(),
            fps: 0.0,
            outbox_len: unsynced_len,
            serial_ok: check_serial_status(),
            ip_address: get_local_ip_to(&self.api_url),
            templates_count: tmpl_count,
            templates_hash: tmpl_hash,
            quarantined_templates_count: quarantined_cnt,
        };

        let url = format!("{}/api/v1/devices/heartbeat", self.api_url);
        let resp = self
            .client
            .post(&url)
            .headers(self.auth_headers())
            .json(&payload)
            .send()
            .await?;

        if !resp.status().is_success() {
            let status = resp.status();
            let body = resp.text().await.unwrap_or_default();
            warn!("Heartbeat gagal (HTTP {}): {}", status, body);
        } else if let Ok(hb_resp) = resp.json::<serde_json::Value>().await {
            if hb_resp.get("action").and_then(|v| v.as_str()) == Some("reconcile_templates") {
                info!("[REKONSILIASI] Server mendeteksi perbedaan checksum template. Memulai sinkronisasi snapshot jaring pengaman.");
                if let Err(e) = self.reconcile_templates().await {
                    error!("Gagal rekonsiliasi templates: {:#}", e);
                }
            }
        }

        Ok(())
    }

    /// Tarik snapshot template aktif dari server dan bersihkan ghost template secara aman (FR-E11 / Langkah 4)
    pub async fn reconcile_templates(&self) -> Result<()> {
        let url = format!("{}/api/v1/templates/snapshot", self.api_url);
        let resp = self
            .client
            .get(&url)
            .headers(self.auth_headers())
            .send()
            .await
            .context("Gagal menghubungi endpoint snapshot templates")?;

        if !resp.status().is_success() {
            anyhow::bail!("Snapshot templates gagal dengan HTTP {}", resp.status());
        }

        let json: serde_json::Value = resp.json().await?;
        let valid_ids_arr = json.get("valid_student_ids").and_then(|v| v.as_array());

        let valid_ids: Vec<String> = match valid_ids_arr {
            Some(arr) => arr.iter().filter_map(|v| v.as_str().map(|s| s.to_string())).collect(),
            None => {
                warn!("[SAFETY GUARD] Respon snapshot server tidak valid. Membatalkan rekonsiliasi.");
                return Ok(());
            }
        };

        // SAFETY GUARD 1: Tolak pembersihan jika server mengembalikan daftar kosong
        if valid_ids.is_empty() {
            warn!("[SAFETY GUARD] Server mengembalikan 0 template aktif. Pembersihan dibatalkan demi keamanan terminal.");
            return Ok(());
        }

        // SAFETY GUARD 2: Batas persentase pembersihan aman (>30% jika data lokal > 5 siswa)
        let (local_count, _) = self.db.get_templates_hash().await.unwrap_or((0, String::new()));
        if local_count > 5 {
            let valid_set: std::collections::HashSet<&str> = valid_ids.iter().map(|s| s.as_str()).collect();
            let local_templates = match self.db.load_templates(self.embed_key.as_deref(), &self.model_version).await {
                Ok(t) => t,
                Err(e) => {
                    warn!("[SAFETY GUARD] Gagal membaca template lokal saat rekonsiliasi: {:#}. Rekonsiliasi dibatalkan untuk mencegah pembersihan data valid.", e);
                    return Ok(());
                }
            };
            let delete_count = local_templates.iter().filter(|t| !valid_set.contains(t.student_id.as_str())).count();

            if (delete_count * 100) / local_count > 30 {
                warn!(
                    "[SAFETY GUARD] Rekonsiliasi dibatalkan: Jumlah template yang akan dihapus ({} dari {} = {}%) melebihi ambang batas keamanan 30%. Memerlukan peninjauan admin.",
                    delete_count, local_count, (delete_count * 100) / local_count
                );
                return Ok(());
            }
        }

        let purged = self.db.purge_unlisted_templates(&valid_ids).await?;
        if purged > 0 {
            info!("[REKONSILIASI SELESAI] Sebanyak {} ghost template berhasil dibersihkan dari database lokal.", purged);
        } else {
            info!("[REKONSILIASI SELESAI] Database lokal sudah selaras dengan server.");
        }

        // Jika jumlah template lokal masih kurang dari valid_ids server, reset kursor dan tarik segera
        let (local_after, _) = self.db.get_templates_hash().await.unwrap_or((0, String::new()));
        if local_after < valid_ids.len() {
            info!("[REKONSILIASI] Template lokal ({}) kurang dari template aktif server ({}). Mereset kursor sync dan menarik template...", local_after, valid_ids.len());
            self.db.reset_template_sync_cursor().await?;
            self.pull_templates().await?;
        }

        Ok(())
    }
}

// ── Sensor & Telemetri Hardware Riil (Linux / Orange Pi) ─────────────────────

fn read_soc_temp() -> f64 {
    for p in &[
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/devices/virtual/thermal/thermal_zone0/temp",
    ] {
        if let Ok(c) = std::fs::read_to_string(p) {
            if let Ok(milli) = c.trim().parse::<f64>() {
                return (milli / 1000.0 * 10.0).round() / 10.0;
            }
        }
    }
    0.0
}

fn read_free_ram_mb() -> i64 {
    if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
        for line in c.lines() {
            if line.starts_with("MemAvailable:") || line.starts_with("MemFree:") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 2 {
                    if let Ok(kb) = parts[1].parse::<i64>() {
                        return kb / 1024;
                    }
                }
            }
        }
    }
    0
}

fn read_free_disk_mb() -> i64 {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;
        let path = CString::new("/").unwrap();
        // SAFETY: statvfs writes to valid C string path and uninit statvfs buffer.
        // If 0 (success) is returned, buffer is fully populated by kernel.
        unsafe {
            if libc::statvfs(path.as_ptr(), stat.as_mut_ptr()) == 0 {
                let s = stat.assume_init();
                // Note: statvfs fields (f_bavail, f_frsize) are u32 on 32-bit architectures (e.g. armv7),
                // so u64 casts are required for cross-platform portability.
                #[allow(clippy::unnecessary_cast)]
                let free_bytes = (s.f_bavail as u64) * (s.f_frsize as u64);
                return (free_bytes / (1024 * 1024)) as i64;
            }
        }
    }
    0
}

fn get_local_ip_to(api_url: &str) -> String {
    let clean = api_url.trim_start_matches("http://").trim_start_matches("https://");
    let host_port = clean.split('/').next().unwrap_or("1.1.1.1:80");
    let host = host_port.split(':').next().unwrap_or("1.1.1.1");
    let target = format!("{}:80", host);

    if let Ok(sock) = std::net::UdpSocket::bind("0.0.0.0:0") {
        if sock.connect(&target).is_ok() {
            if let Ok(addr) = sock.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && ip != "127.0.0.1" {
                    return ip;
                }
            }
        }
        if sock.connect("10.255.255.255:1").is_ok() {
            if let Ok(addr) = sock.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && ip != "127.0.0.1" {
                    return ip;
                }
            }
        }
    }
    "127.0.0.1".to_string()
}

fn check_serial_status() -> bool {
    std::path::Path::new("/dev/ttyACM0").exists()
        || std::path::Path::new("/dev/ttyUSB0").exists()
}

#[cfg(unix)]
pub fn notify_systemd(state: &str) {
    use std::os::unix::net::UnixDatagram;
    if let Ok(sock_path) = std::env::var("NOTIFY_SOCKET") {
        if let Ok(sock) = UnixDatagram::unbound() {
            let _ = sock.send_to(state.as_bytes(), sock_path);
        }
    }
}

#[cfg(not(unix))]
pub fn notify_systemd(_state: &str) {}

// ── Device-Initiated Pairing (TV / IoT Style) ────────────────────────────────

#[derive(Debug, Serialize)]
pub struct PairingInitRequest {
    pub hostname: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub mac_address: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub model_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub ip_address: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct PairingInitResponse {
    pub pairing_code: String,
    pub poll_token: String,
    pub expires_in: u64,
    pub interval: u64,
}

#[derive(Debug, Serialize)]
pub struct PairingPollRequest {
    pub poll_token: String,
}

#[derive(Debug, Deserialize)]
pub struct PairingPollResponse {
    pub status: String,
    #[serde(default)]
    pub message: Option<String>,
    #[serde(default)]
    pub pairing_code: Option<String>,
    #[serde(default)]
    pub url: Option<String>,
    #[serde(default)]
    pub token: Option<String>,
    #[serde(default)]
    pub device_id: Option<String>,
    #[serde(default)]
    pub device_code: Option<String>,
    #[serde(default)]
    pub embed_key: Option<String>,
    #[serde(default)]
    pub model_version: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PairedCredentials {
    pub url: String,
    pub token: String,
    pub device_id: String,
    pub device_code: String,
    pub embed_key: String,
    pub model_version: Option<String>,
}

pub fn detect_mac_address() -> Option<String> {
    for iface in &["eth0", "wlan0", "en0", "end0", "wlp2s0", "wlan1"] {
        let path = format!("/sys/class/net/{}/address", iface);
        if let Ok(content) = std::fs::read_to_string(&path) {
            let mac = content.trim().to_string();
            if !mac.is_empty() && mac != "00:00:00:00:00:00" {
                return Some(mac);
            }
        }
    }
    None
}

pub fn detect_hostname() -> String {
    if let Ok(h) = std::env::var("HOSTNAME") {
        if !h.trim().is_empty() {
            return h.trim().to_string();
        }
    }
    if let Ok(content) = std::fs::read_to_string("/etc/hostname") {
        let h = content.trim();
        if !h.is_empty() {
            return h.to_string();
        }
    }
    "orangepilite2".to_string()
}

pub fn write_env_atomically(env_path: &Path, creds: &PairedCredentials) -> Result<()> {
    if let Some(parent) = env_path.parent() {
        if !parent.as_os_str().is_empty() {
            let _ = std::fs::create_dir_all(parent);
        }
    }

    let tmp_path = env_path.with_extension("tmp");
    let content = format!(
        "SMART_ABSENSI_URL={}\nSMART_ABSENSI_TOKEN={}\nSMART_ABSENSI_DEVICE_ID={}\nENROLLMENT_EMBED_KEY={}\nMODEL_VERSION={}\n",
        creds.url,
        creds.token,
        creds.device_code,
        creds.embed_key,
        creds.model_version.as_deref().unwrap_or("v1.0-yunet")
    );

    // Remove any stale tmp file from a previous crash so permissions/inode are not reused
    let _ = std::fs::remove_file(&tmp_path);

    #[cfg(unix)]
    {
        use std::io::Write;
        use std::os::unix::fs::OpenOptionsExt;
        let mut file = std::fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&tmp_path)
            .with_context(|| format!("Gagal membuat file konfigurasi baru dengan mode 0600 {:?}", tmp_path))?;
        file.write_all(content.as_bytes())
            .with_context(|| format!("Gagal menulis data ke file konfigurasi sementara {:?}", tmp_path))?;
        file.sync_all()
            .with_context(|| format!("Gagal flush sync_all ke disk untuk {:?}", tmp_path))?;
    }

    #[cfg(not(unix))]
    {
        use std::io::Write;
        let mut file = std::fs::OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .open(&tmp_path)
            .with_context(|| format!("Gagal menulis file konfigurasi sementara {:?}", tmp_path))?;
        file.write_all(content.as_bytes())
            .with_context(|| format!("Gagal menulis data ke file konfigurasi sementara {:?}", tmp_path))?;
        let _ = file.sync_all();
    }

    std::fs::rename(&tmp_path, env_path)
        .with_context(|| format!("Gagal memindahkan file konfigurasi ke {:?}", env_path))?;

    info!("Konfigurasi tersimpan secara atomik ke {:?} (permission 0600, power-cut synced)", env_path);
    Ok(())
}

pub async fn run_device_initiated_pairing(
    client: &reqwest::Client,
    server_url: &str,
    env_path: &Path,
    model_version_opt: Option<&str>,
) -> Result<PairedCredentials> {
    let clean_url = server_url.trim().trim_end_matches('/');
    let hostname = detect_hostname();
    let mac = detect_mac_address();

    loop {
        info!("Mengirim permintaan pairing ke server: {}/api/v1/devices/pairing/request", clean_url);
        let init_req = PairingInitRequest {
            hostname: hostname.clone(),
            mac_address: mac.clone(),
            model_version: model_version_opt.map(|s| s.to_string()),
            ip_address: None,
        };

        let init_res = match client
            .post(&format!("{}/api/v1/devices/pairing/request", clean_url))
            .json(&init_req)
            .send()
            .await
        {
            Ok(r) => r,
            Err(e) => {
                warn!("Gagal menghubungi server ({:?}). Mencoba lagi dalam 5 detik...", e);
                tokio::time::sleep(Duration::from_secs(5)).await;
                continue;
            }
        };

        if !init_res.status().is_success() {
            let status = init_res.status();
            let body = init_res.text().await.unwrap_or_default();
            warn!("Server mengembalikan status HTTP {}: {}. Menunggu 5 detik...", status, body);
            tokio::time::sleep(Duration::from_secs(5)).await;
            continue;
        }

        let init: PairingInitResponse = init_res.json().await?;
        info!("================================================================");
        info!(" [MODE PAIRING PERANGKAT]");
        info!(" Kode Pairing : {}", init.pairing_code);
        info!(" MAC Address  : {}", mac.as_deref().unwrap_or("Tidak terdeteksi"));
        info!(" Hostname     : {}", hostname);
        info!(" Petunjuk     : Buka Dashboard Admin -> Menu Perangkat -> Setujui");
        info!("================================================================");

        let poll_interval = Duration::from_secs(init.interval.max(2));
        let mut loop_count = 0u64;

        loop {
            notify_systemd("WATCHDOG=1\n");
            tokio::time::sleep(poll_interval).await;
            loop_count += 1;

            let poll_req = PairingPollRequest {
                poll_token: init.poll_token.clone(),
            };

            let poll_res = match client
                .post(&format!("{}/api/v1/devices/pairing/poll", clean_url))
                .json(&poll_req)
                .send()
                .await
            {
                Ok(r) => r,
                Err(e) => {
                    if loop_count % 5 == 0 {
                        warn!("Gagal polling server ({:?}). Tetap mencoba...", e);
                    }
                    continue;
                }
            };

            let status = poll_res.status();
            if status == reqwest::StatusCode::GONE {
                warn!("Kode pairing {} kedaluwarsa atau sudah pernah diklaim. Mengulang permohonan baru...", init.pairing_code);
                break;
            }

            if status == reqwest::StatusCode::FORBIDDEN {
                warn!("Permintaan pairing ditolak oleh administrator. Mencoba permohonan baru dalam 10 detik...");
                tokio::time::sleep(Duration::from_secs(10)).await;
                break;
            }

            if !status.is_success() {
                if loop_count % 5 == 0 {
                    warn!("Status HTTP {} saat polling: {:?}", status, poll_res.text().await.unwrap_or_default());
                }
                continue;
            }

            let poll_body: PairingPollResponse = poll_res.json().await?;
            if poll_body.status == "pending" {
                if loop_count % 4 == 0 {
                    info!("Menunggu persetujuan admin... (Kode: {})", init.pairing_code);
                }
                continue;
            }

            if poll_body.status == "approved" {
                let token = poll_body.token.context("Token tidak ada dalam respons pairing")?;
                let device_code = poll_body.device_code.context("Device code tidak ada dalam respons pairing")?;
                let embed_key = poll_body.embed_key.unwrap_or_else(|| "t7rXp59ZkE+L9Y6qP3R8FmNcK5WxYJ2HhDbjPvC4RnE=".to_string());
                let url = poll_body.url.unwrap_or_else(|| clean_url.to_string());

                let creds = PairedCredentials {
                    url,
                    token,
                    device_id: poll_body.device_id.unwrap_or_else(|| device_code.clone()),
                    device_code,
                    embed_key,
                    model_version: poll_body.model_version,
                };

                write_env_atomically(env_path, &creds)?;
                info!("Perangkat BERHASIL dipasangkan! Melanjutkan ke mode operasional normal...");
                return Ok(creds);
            }
        }
    }
}

pub async fn ensure_authenticated_or_pair(
    api_url: &str,
    api_token: &str,
    embed_key_opt: Option<String>,
    env_path: &Path,
    model_version: &str,
) -> Result<(String, Option<String>)> {
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(15))
        .build()?;

    let mut current_token = api_token.trim().to_string();
    let mut current_embed_key = embed_key_opt;

    // 1. Jika token kosong: otomatis masuk mode auto-pairing
    if current_token.is_empty() {
        info!("Token API tidak ditemukan. Memasuki mode Device-Initiated Auto-Pairing...");
        let creds = run_device_initiated_pairing(&client, api_url, env_path, Some(model_version)).await?;
        current_token = creds.token;
        current_embed_key = Some(creds.embed_key);
        return Ok((current_token, current_embed_key));
    }

    // 2. Jika token ada: verifikasi ke server dengan heartbeat singkat
    let test_hb = client
        .post(&format!("{}/api/v1/devices/heartbeat", api_url.trim_end_matches('/')))
        .header(reqwest::header::AUTHORIZATION, format!("Bearer {}", current_token))
        .header(reqwest::header::CONTENT_TYPE, "application/json")
        .header(reqwest::header::ACCEPT, "application/json")
        .json(&serde_json::json!({
            "fw_version": "v1.0.0-rust",
            "model_version": model_version,
            "cpu_temp": 0.0,
            "ram_free_mb": 0,
            "disk_free_mb": 0,
            "fps": 0.0,
            "outbox_len": 0,
            "serial_ok": false,
            "ip_address": "127.0.0.1"
        }))
        .send()
        .await;

    if let Ok(resp) = test_hb {
        if resp.status() == reqwest::StatusCode::UNAUTHORIZED {
            warn!("Token saat ini tidak valid atau telah dicabut (HTTP 401). Memasuki mode Device-Initiated Auto-Pairing ulang...");
            let creds = run_device_initiated_pairing(&client, api_url, env_path, Some(model_version)).await?;
            current_token = creds.token;
            current_embed_key = Some(creds.embed_key);
        }
    }

    Ok((current_token, current_embed_key))
}

// ── Unit & Integration Tests ─────────────────────────────────────────────────

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    #[tokio::test]
    async fn test_push_outbox_mixed_batch_handling() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        tokio::spawn(async move {
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 4096];
                let _ = socket.read(&mut buf).await.unwrap_or(0);

                let body = "{\"results\":[{\"id\":\"att-ok-1\",\"status\":\"created\"},{\"id\":\"att-dup-2\",\"status\":\"duplicate\"},{\"id\":\"att-rej-3\",\"status\":\"rejected\",\"reason\":\"server_error\"}]}";
                let response = format!(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(),
                    body
                );
                let _ = socket.write_all(response.as_bytes()).await;
            }
        });

        let db = EdgeDb::init(":memory:").await.unwrap();
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-ok-1', 'std-1', '2026-10-12 10:00:00', 'in', 0.95, 0.98, 'rtc', 0), \
                        ('att-dup-2', 'std-2', '2026-10-12 10:00:01', 'in', 0.95, 0.98, 'rtc', 0), \
                        ('att-rej-3', 'std-3', '2026-10-12 10:00:02', 'in', 0.95, 0.98, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        let worker = SyncWorker::new(
            db,
            api_url,
            "test-token-123".to_string(),
            None,
            "v1.0-yunet".to_string(),
        )
        .unwrap();

        let (synced, rejected) = worker.push_outbox_to_laravel().await.unwrap();
        assert_eq!(synced, 2, "Harus mensinkronkan 2 record (created + duplicate)");
        assert_eq!(rejected, 1, "Harus mendeteksi 1 record rejected");

        // Record yang rejected HARUS tetap berada di outbox (synced = 0)
        let unsynced = worker.db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 1, "Hanya record rejected yang tersisa di outbox");
        assert_eq!(unsynced[0].id, "att-rej-3");
    }

    #[tokio::test]
    async fn test_push_outbox_handles_422_poisoned_batch() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        // Server handler:
        // - Panggilan 1: Batch berisi 2 record -> return 422 Unprocessable Entity
        // - Panggilan 2: Individual record 'att-poison' -> return 422 Unprocessable Entity
        // - Panggilan 3: Individual record 'att-good' -> return 200 OK
        tokio::spawn(async move {
            let mut call_count = 0;
            while let Ok((mut socket, _)) = listener.accept().await {
                call_count += 1;
                let mut buf = [0u8; 4096];
                let n = socket.read(&mut buf).await.unwrap_or(0);
                let req_text = String::from_utf8_lossy(&buf[..n]);

                if call_count == 1 {
                    // Panggilan batch pertama gagal 422
                    let body = "{\"message\":\"Validation error: invalid student_id format\"}";
                    let resp = format!(
                        "HTTP/1.1 422 Unprocessable Entity\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                        body.len(),
                        body
                    );
                    let _ = socket.write_all(resp.as_bytes()).await;
                } else if req_text.contains("att-poison") {
                    let body = "{\"message\":\"Invalid student_id\"}";
                    let resp = format!(
                        "HTTP/1.1 422 Unprocessable Entity\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                        body.len(),
                        body
                    );
                    let _ = socket.write_all(resp.as_bytes()).await;
                } else {
                    let body = "{\"results\":[{\"id\":\"att-good\",\"status\":\"created\"}]}";
                    let resp = format!(
                        "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                        body.len(),
                        body
                    );
                    let _ = socket.write_all(resp.as_bytes()).await;
                }
            }
        });

        let db = EdgeDb::init(":memory:").await.unwrap();
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-poison', 'bad-id', '2026-10-12 10:00:00', 'in', 0.95, 0.98, 'rtc', 0), \
                        ('att-good', 'std-valid', '2026-10-12 10:00:01', 'in', 0.95, 0.98, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        let worker = SyncWorker::new(
            db,
            api_url,
            "test-token-123".to_string(),
            None,
            "v1.0-yunet".to_string(),
        )
        .unwrap();

        // Push batch akan menangani 422, memecah batch, mengkarantina 'att-poison', dan mensinkronkan 'att-good'
        let (synced, quarantined) = worker.push_outbox_to_laravel().await.unwrap();
        assert_eq!(synced, 1, "Record baik harus berhasil disinkronkan");
        assert_eq!(quarantined, 1, "Record beracun harus dikarantina");

        // Antrean outbox aktif (synced = 0) sekarang harus kosong (tidak terblokir!)
        let unsynced = worker.db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 0, "Antrean outbox harus bersih tidak terblokir");
    }

    #[tokio::test]
    async fn test_push_outbox_failure_keeps_records_unsynced() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        tokio::spawn(async move {
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 1024];
                let _ = socket.read(&mut buf).await;
                let response = "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 15\r\nConnection: close\r\n\r\nServer Error 500";
                let _ = socket.write_all(response.as_bytes()).await;
            }
        });

        let db = EdgeDb::init(":memory:").await.unwrap();
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-err-1', 'std-2', '2026-10-12 10:00:00', 'in', 0.95, 0.98, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        let worker = SyncWorker::new(
            db,
            api_url,
            "test-token-123".to_string(),
            None,
            "v1.0-yunet".to_string(),
        )
        .unwrap();

        let result = worker.push_outbox_to_laravel().await;
        assert!(result.is_err(), "Harus mengembalikan error jika server 500");

        let unsynced = worker.db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 1, "Record harus tetap unsynced untuk retry");
        assert_eq!(unsynced[0].id, "att-err-1");
    }

    #[test]
    fn test_write_env_atomically() {
        let temp_env = std::env::temp_dir().join(format!(
            "test_env_{}.env",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));

        let creds = PairedCredentials {
            url: "http://192.168.1.100:8000".to_string(),
            token: "test-sanctum-token".to_string(),
            device_id: "dev-0001".to_string(),
            device_code: "dev-0001".to_string(),
            embed_key: "test-key-32-bytes".to_string(),
            model_version: Some("v1.0-yunet".to_string()),
        };

        write_env_atomically(&temp_env, &creds).unwrap();
        assert!(temp_env.exists());
        let content = std::fs::read_to_string(&temp_env).unwrap();
        assert!(content.contains("SMART_ABSENSI_TOKEN=test-sanctum-token"));
        assert!(content.contains("SMART_ABSENSI_DEVICE_ID=dev-0001"));
        let _ = std::fs::remove_file(temp_env);
    }

    #[tokio::test]
    async fn test_run_device_initiated_pairing_flow() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        tokio::spawn(async move {
            // 1. Accept request for init
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 2048];
                let _ = socket.read(&mut buf).await;
                let body = r#"{"pairing_code":"839-204","poll_token":"test-poll-token-123","expires_in":600,"interval":1}"#;
                let response = format!(
                    "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(),
                    body
                );
                let _ = socket.write_all(response.as_bytes()).await;
            }

            // 2. Accept poll -> return approved
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 2048];
                let _ = socket.read(&mut buf).await;
                let body = r#"{"status":"approved","token":"sanctum-token-abc","device_id":"dev-0001","device_code":"dev-0001","embed_key":"test-key","model_version":"v0.2.0","url":"http://127.0.0.1:8000"}"#;
                let response = format!(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(),
                    body
                );
                let _ = socket.write_all(response.as_bytes()).await;
            }
        });

        let temp_env = std::env::temp_dir().join(format!(
            "test_pairing_{}.env",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));

        let client = reqwest::Client::new();
        let creds = run_device_initiated_pairing(&client, &api_url, &temp_env, Some("v0.2.0"))
            .await
            .unwrap();

        assert_eq!(creds.token, "sanctum-token-abc");
        assert_eq!(creds.device_code, "dev-0001");
        assert!(temp_env.exists());
        let _ = std::fs::remove_file(temp_env);
    }
}
