use anyhow::{Context, Result};
use engine_core::{AttendanceBatchPayload, AttendancePayloadRecord, EdgeDb, HeartbeatPayload};
use reqwest::header::{HeaderMap, HeaderValue, ACCEPT, AUTHORIZATION, CONTENT_TYPE};
use std::time::Duration;
use tracing::{error, info, warn};

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
            model_version: "v1.0-yunet".to_string(),
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
    pub async fn run_loop(&self, interval_secs: u64) {
        let mut interval = tokio::time::interval(Duration::from_secs(interval_secs));
        let mut consecutive_failures = 0u32;

        info!("SyncWorker daemon berjalan (interval: {}s)", interval_secs);

        loop {
            interval.tick().await;

            // 1. Sync Turso Replica jika aktif
            if let Err(e) = self.db.sync().await {
                error!("Gagal menyinkronkan Turso replica: {:?}", e);
            }

            // 2. Kirim Heartbeat ke Laravel API
            if let Err(e) = self.send_heartbeat().await {
                warn!("Gagal mengirim heartbeat: {:?}", e);
            }

            // 3. Push Outbox ke Laravel API dengan backoff
            match self.push_outbox_to_laravel().await {
                Ok(count) => {
                    if count > 0 {
                        info!("Berhasil sinkronisasi {} record absensi ke Laravel", count);
                    }
                    consecutive_failures = 0;
                }
                Err(e) => {
                    consecutive_failures += 1;
                    let backoff = std::cmp::min(30, 2u64.pow(consecutive_failures.min(5)));
                    error!(
                        "Gagal push outbox absensi ke Laravel (percobaan #{}): {:?}. Menunggu {}s",
                        consecutive_failures, e, backoff
                    );
                    tokio::time::sleep(Duration::from_secs(backoff)).await;
                }
            }

            // 4. Pull Pembaruan Wajah / Template
            if let Err(e) = self.pull_templates().await {
                warn!("Gagal pull templates wajah: {:?}", e);
            }
        }
    }

    /// Push pending rows from outbox_attendance to Laravel /api/v1/attendance/batch
    pub async fn push_outbox_to_laravel(&self) -> Result<usize> {
        let records = self.db.get_unsynced_attendance(50).await?;
        if records.is_empty() {
            return Ok(0);
        }

        let record_ids: Vec<String> = records.iter().map(|r| r.id.clone()).collect();
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
            // Tandai sebagai synced hanya setelah mendapatkan respons HTTP 2xx
            self.db.mark_as_synced(&record_ids).await?;
            Ok(record_ids.len())
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
            if !items.is_empty() || next_cursor.is_some() {
                self.db.save_templates(items, next_cursor).await?;
                info!("Berhasil menyimpan {} update template wajah", items.len());
            }
        }

        Ok(())
    }

    /// Kirim heartbeat telemetry ke Laravel /api/v1/devices/heartbeat
    pub async fn send_heartbeat(&self) -> Result<()> {
        let unsynced_len = self.db.get_unsynced_count().await.unwrap_or(0);
        let payload = HeartbeatPayload {
            fw_version: self.fw_version.clone(),
            model_version: self.model_version.clone(),
            cpu_temp: 45.0,
            ram_free_mb: 512,
            disk_free_mb: 1024,
            fps: 0.0,
            outbox_len: unsynced_len,
            serial_ok: true,
            ip_address: "127.0.0.1".to_string(),
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

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    #[tokio::test]
    async fn test_push_outbox_success_and_idempotency() {
        // 1. Jalankan mock HTTP server lokal
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let api_url = format!("http://127.0.0.1:{}", port);

        // Server handler untuk POST /api/v1/attendance/batch
        tokio::spawn(async move {
            if let Ok((mut socket, _)) = listener.accept().await {
                let mut buf = [0u8; 4096];
                let n = socket.read(&mut buf).await.unwrap_or(0);
                let req_text = String::from_utf8_lossy(&buf[..n]);

                // Verifikasi Authorization header dan method
                assert!(req_text.contains("POST /api/v1/attendance/batch"));
                assert!(req_text.contains("Bearer test-token-123"));
                assert!(req_text.contains("att-test-1"));

                let response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 26\r\n\r\n{\"results\":[{\"status\":\"ok\"}]}";
                let _ = socket.write_all(response.as_bytes()).await;
            }
        });

        // 2. Setup DB dengan 1 record outbox
        let db = EdgeDb::init(":memory:", None, None).await.unwrap();
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-test-1', 'std-1', '2026-10-12 10:00:00', 'in', 0.95, 0.98, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        let worker = SyncWorker::new(db, api_url, "test-token-123".to_string(), None).unwrap();

        // 3. Jalankan push
        let pushed_count = worker.push_outbox_to_laravel().await.unwrap();
        assert_eq!(pushed_count, 1);

        // 4. Verifikasi record ditandai sebagai synced = 1
        let unsynced = worker.db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 0, "Record harus sudah tersinkronisasi");
    }

    #[tokio::test]
    async fn test_push_outbox_failure_keeps_records_unsynced() {
        // Mock server yang mengembalikan HTTP 500 error
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

        let db = EdgeDb::init(":memory:", None, None).await.unwrap();
        db.conn
            .execute(
                "INSERT INTO outbox_attendance (id, student_id, captured_at, direction, score, liveness_score, time_source, synced) \
                 VALUES ('att-err-1', 'std-2', '2026-10-12 10:00:00', 'in', 0.95, 0.98, 'rtc', 0)",
                (),
            )
            .await
            .unwrap();

        let worker = SyncWorker::new(db, api_url, "test-token-123".to_string(), None).unwrap();

        // Push harus menghasilkan error
        let result = worker.push_outbox_to_laravel().await;
        assert!(result.is_err(), "Harus mengembalikan error jika server 500");

        // Record tetap berstatus unsynced (synced = 0) sehingga bisa di-retry tanpa duplikasi
        let unsynced = worker.db.get_unsynced_attendance(50).await.unwrap();
        assert_eq!(unsynced.len(), 1, "Record harus tetap unsynced untuk retry");
        assert_eq!(unsynced[0].id, "att-err-1");
    }
}


