use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum DeviceStatus {
    PendingPairing,
    Online,
    Offline,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EdgeDevice {
    pub id: String,
    pub hostname: String,
    pub ip_address: String,
    pub mac_address: String,
    pub pairing_code: Option<String>,
    pub status: DeviceStatus,
    pub engine_version: String,
    pub temperature_c: f32,
    pub last_seen_sec: u64,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum FilterTab {
    All,
    Pending,
    Online,
    Offline,
}
