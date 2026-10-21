use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OutboxRecord {
    pub id: String,
    pub student_id: String,
    pub captured_at: String,
    pub direction: String,
    pub score: f64,
    pub liveness_score: f64,
    pub time_source: String,
    pub synced: i64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AttendancePayloadRecord {
    pub id: String,
    pub student_id: String,
    pub captured_at: String,
    pub direction: String,
    pub score: f64,
    pub liveness_score: f64,
    pub time_source: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AttendanceBatchPayload {
    pub records: Vec<AttendancePayloadRecord>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HeartbeatPayload {
    pub fw_version: String,
    pub model_version: String,
    pub cpu_temp: f64,
    pub ram_free_mb: i64,
    pub disk_free_mb: i64,
    pub fps: f64,
    pub outbox_len: usize,
    pub serial_ok: bool,
    pub ip_address: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub templates_count: Option<usize>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub templates_hash: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub quarantined_templates_count: Option<usize>,
}

#[derive(Debug, Clone)]
pub struct TemplateRecord {
    pub student_id: String,
    pub name: String,
    pub embedding: Vec<f32>,
}
