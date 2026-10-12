use anyhow::{Context, Result};
use engine_core::{AttendanceBatchPayload, AttendancePayloadRecord, EdgeDb, HeartbeatPayload};
use reqwest::header::{HeaderMap, HeaderValue, ACCEPT, AUTHORIZATION, CONTENT_TYPE};
use serde::Deserialize;
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
    /// - Outbox check: tiap 15s (FR-E10)
    /// - Template & config sync: tiap 60s (FR-E11)
    /// - Heartbeat: tiap 60s (FR-E12)
    /// - Backoff schedule: 5s, 30s, 120s (2m), 600s (10m), 900s (15m)
    pub async fn run_loop(&self) {
        let mut outbox_interval = tokio::time::interval(Duration::from_secs(15));
        let mut template_interval = tokio::time::interval(Duration::from_secs(60));
        let mut heartbeat_interval = tokio::time::interval(Duration::from_secs(60));

        const BACKOFF_SCHEDULE: [u64; 5] = [5, 30, 120, 600, 900];
        let mut failure_streak = 0usize;

        info!(
            "SyncWorker daemon berjalan (Outbox: 15s, Templates: 60s, Heartbeat: 60s, Model: {})",
            self.model_version
        );

        loop {
            tokio::select! {
                _ = outbox_interval.tick() => {
                    notify_systemd_watchdog();

                    match self.push_outbox_to_laravel().await {
                        Ok((synced, rejected)) => {
                            if synced > 0 || rejected > 0 {
                                info!(
                                    "Outbox sync: {} disinkronkan, {} ditolak (tersisa di outbox)",
                                    synced, rejected
                                );
                            }
                            failure_streak = 0;
                        }
                        Err(e) => {
                            let backoff = BACKOFF_SCHEDULE[failure_streak.min(BACKOFF_SCHEDULE.len() - 1)];
                            failure_streak += 1;
                            error!(
                                "Gagal push outbox absensi ke Laravel (kegagalan #{}): {:?}. Menunggu {}s (FR-E10 backoff)",
                                failure_streak, e, backoff
                            );
                            tokio::time::sleep(Duration::from_secs(backoff)).await;
                        }
                    }
                }

                _ = template_interval.tick() => {
                    if let Err(e) = self.pull_templates().await {
                        warn!("Gagal pull templates wajah: {:?}", e);
                    }
                }

                _ = heartbeat_interval.tick() => {
                    if let Err(e) = self.send_heartbeat().await {
                        warn!("Gagal mengirim heartbeat: {:?}", e);
                    }
                }
            }
        }
    }

    /// Push pending rows from outbox_attendance to Laravel /api/v1/attendance/batch
    /// Parsir hasil per-record: hanya tandai status 'created' dan 'duplicate' sebagai synced.
    /// Record dengan status 'rejected' tetap dibiarkan di outbox (synced = 0) agar tidak hilang.
    pub async fn push_outbox_to_laravel(&self) -> Result<(usize, usize)> {
        let records = self.db.get_unsynced_attendance(50).await?;
        if records.is_empty() {
            return Ok((0, 0));
        }

        let payload_records: Vec<AttendancePayloadRecord> = records
            .into_iter()
            .map(|r| AttendancePayloadRecord {
                id: r.id,
                student_id: r.student_id,
                captured_at: r.captured_at,
                direction: r.direction,
                score: (r.score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                liveness_score: (r.liveness_score.clamp(0.0, 1.0) * 10000.0).round() / 10000.0,
                time_source: r.time_source,
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
        } else {
            let body = resp.text().await.unwrap_or_default();
            anyhow::bail!(
                "Laravel API returned error HTTP {}: {}",
                status.as_u16(),
                body
            );
        }
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
    /// Menggunakan metrik sistem riil (suhu SoC, RAM bebas, disk bebas, outbox, IP lokal)
    pub async fn send_heartbeat(&self) -> Result<()> {
        let unsynced_len = self.db.get_unsynced_count().await.unwrap_or(0);
        let payload = HeartbeatPayload {
            fw_version: self.fw_version.clone(),
            model_version: self.model_version.clone(),
            cpu_temp: read_soc_temp(),
            ram_free_mb: read_free_ram_mb(),
            disk_free_mb: read_free_disk_mb(),
            fps: 0.0,
            outbox_len: unsynced_len,
            serial_ok: check_serial_status(),
            ip_address: get_local_ip(),
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
    // Membaca statvfs jika di Unix, atau fallback
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;
        let path = CString::new("/").unwrap();
        let mut stat: MaybeUninit<libc::statvfs> = MaybeUninit::uninit();
        unsafe {
            if libc::statvfs(path.as_ptr(), stat.as_mut_ptr()) == 0 {
                let s = stat.assume_init();
                let free_bytes = (s.f_bavail as u64) * (s.f_frsize as u64);
                return (free_bytes / (1024 * 1024)) as i64;
            }
        }
    }
    0
}

fn get_local_ip() -> String {
    let s = std::net::UdpSocket::bind("0.0.0.0:0").ok();
    if let Some(sock) = s {
        if sock.connect("10.255.255.255:1").is_ok() {
            if let Ok(addr) = sock.local_addr() {
                return addr.ip().to_string();
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
fn notify_systemd_watchdog() {
    use std::os::unix::net::UnixDatagram;
    if let Ok(sock_path) = std::env::var("NOTIFY_SOCKET") {
        if let Ok(sock) = UnixDatagram::unbound() {
            let _ = sock.send_to(b"WATCHDOG=1\n", sock_path);
        }
    }
}

#[cfg(not(unix))]
fn notify_systemd_watchdog() {}

// ── Unit & Integration Tests ─────────────────────────────────────────────────

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    #[tokio::test]
    async fn test_push_outbox_mixed_batch_handling() {
        // Mock server yang mengembalikan kombinasi created, duplicate, dan rejected
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        tokio::spawn(async move {
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 4096];
                let _ = socket.read(&mut buf).await.unwrap_or(0);

                let body = "{\"results\":[{\"id\":\"att-ok-1\",\"status\":\"created\"},{\"id\":\"att-dup-2\",\"status\":\"duplicate\"},{\"id\":\"att-rej-3\",\"status\":\"rejected\",\"reason\":\"server_error\"}]}";
                let response = format!("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}", body.len(), body);
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
    async fn test_push_outbox_failure_keeps_records_unsynced() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        tokio::spawn(async move {
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 1024];
                let _ = socket.read(&mut buf).await;
                let response = "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 15\r\n\r\nServer Error 500";
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
}
