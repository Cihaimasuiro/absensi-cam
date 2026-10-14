use crate::device::{DeviceStatus, EdgeDevice};

pub trait ApiClient {
    fn list_devices(&self) -> Result<Vec<EdgeDevice>, String>;
    fn approve_device(&mut self, id: &str) -> Result<(), String>;
    fn reject_device(&mut self, id: &str) -> Result<(), String>;
    fn rename_device(&mut self, id: &str, new_hostname: &str) -> Result<(), String>;
    fn forget_device(&mut self, id: &str) -> Result<(), String>;
}

#[derive(Debug, Clone)]
pub struct MockApiClient {
    devices: Vec<EdgeDevice>,
}

impl Default for MockApiClient {
    fn default() -> Self {
        Self::new()
    }
}

impl MockApiClient {
    pub fn new() -> Self {
        Self {
            devices: vec![
                EdgeDevice {
                    id: "dev-04".to_string(),
                    hostname: "orangepi-new-device".to_string(),
                    ip_address: "192.168.1.88".to_string(),
                    mac_address: "02:42:C0:A8:01:58".to_string(),
                    pairing_code: Some("PAIR-7842".to_string()),
                    status: DeviceStatus::PendingPairing,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 50.2,
                    last_seen_sec: 12,
                },
                EdgeDevice {
                    id: "dev-01".to_string(),
                    hostname: "orangepi-gate-in".to_string(),
                    ip_address: "192.168.1.50".to_string(),
                    mac_address: "02:42:C0:A8:01:32".to_string(),
                    pairing_code: None,
                    status: DeviceStatus::Online,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 54.8,
                    last_seen_sec: 2,
                },
                EdgeDevice {
                    id: "dev-02".to_string(),
                    hostname: "orangepi-gate-out".to_string(),
                    ip_address: "192.168.1.51".to_string(),
                    mac_address: "02:42:C0:A8:01:33".to_string(),
                    pairing_code: None,
                    status: DeviceStatus::Online,
                    engine_version: "v0.3.0-rust".to_string(),
                    temperature_c: 56.1,
                    last_seen_sec: 5,
                },
                EdgeDevice {
                    id: "dev-03".to_string(),
                    hostname: "orangepi-canteen".to_string(),
                    ip_address: "192.168.1.55".to_string(),
                    mac_address: "02:42:C0:A8:01:37".to_string(),
                    pairing_code: None,
                    status: DeviceStatus::Offline,
                    engine_version: "v0.2.8".to_string(),
                    temperature_c: 0.0,
                    last_seen_sec: 1420,
                },
            ],
        }
    }
}

impl ApiClient for MockApiClient {
    fn list_devices(&self) -> Result<Vec<EdgeDevice>, String> {
        Ok(self.devices.clone())
    }

    fn approve_device(&mut self, id: &str) -> Result<(), String> {
        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
            dev.status = DeviceStatus::Online;
            dev.pairing_code = None;
            Ok(())
        } else {
            Err(format!("Perangkat '{id}' tidak ditemukan"))
        }
    }

    fn reject_device(&mut self, id: &str) -> Result<(), String> {
        let initial_len = self.devices.len();
        self.devices.retain(|d| d.id != id);
        if self.devices.len() < initial_len {
            Ok(())
        } else {
            Err(format!("Perangkat '{id}' tidak ditemukan"))
        }
    }

    fn rename_device(&mut self, id: &str, new_hostname: &str) -> Result<(), String> {
        if let Some(dev) = self.devices.iter_mut().find(|d| d.id == id) {
            dev.hostname = new_hostname.to_string();
            Ok(())
        } else {
            Err(format!("Perangkat '{id}' tidak ditemukan"))
        }
    }

    fn forget_device(&mut self, id: &str) -> Result<(), String> {
        self.reject_device(id)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_list_and_approve() {
        let mut client = MockApiClient::new();
        let devices = client.list_devices().unwrap();
        assert_eq!(devices.len(), 4);

        let pending = devices.iter().find(|d| d.status == DeviceStatus::PendingPairing).unwrap();
        assert_eq!(pending.id, "dev-04");

        assert!(client.approve_device("dev-04").is_ok());

        let updated = client.list_devices().unwrap();
        let approved = updated.iter().find(|d| d.id == "dev-04").unwrap();
        assert_eq!(approved.status, DeviceStatus::Online);
        assert_eq!(approved.pairing_code, None);
    }

    #[test]
    fn test_reject_and_rename() {
        let mut client = MockApiClient::new();
        assert!(client.rename_device("dev-01", "gate-utama").is_ok());

        let devices = client.list_devices().unwrap();
        let renamed = devices.iter().find(|d| d.id == "dev-01").unwrap();
        assert_eq!(renamed.hostname, "gate-utama");

        assert!(client.reject_device("dev-01").is_ok());
        let after_reject = client.list_devices().unwrap();
        assert_eq!(after_reject.len(), 3);
        assert!(after_reject.iter().all(|d| d.id != "dev-01"));
    }
}
