use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FaceTemplate {
    pub student_id: i64,
    pub name: String,
    pub embedding: Vec<f32>,
    pub version: i64,
    pub updated_at: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AttendanceLog {
    pub id: Option<i64>,
    pub student_id: i64,
    pub timestamp: String,
    pub confidence: f32,
    pub liveness_score: f32,
    pub synced: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceConfig {
    pub device_id: String,
    pub api_url: String,
    pub api_token: String,
    pub turso_url: Option<String>,
    pub turso_token: Option<String>,
}
