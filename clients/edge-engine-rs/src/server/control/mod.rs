use axum::{
    routing::{get, post},
    Router,
};

pub mod handlers;
pub mod models;
pub mod system;

pub use handlers::*;
pub use models::*;
pub use system::*;

// ── Router Registration ──────────────────────────────────────────────────────

pub fn register_control_routes(router: Router<ControlState>) -> Router<ControlState> {
    router
        .route("/", get(ui_page_handler))
        .route("/control", get(ui_page_handler))
        .route("/api/control/setup", post(setup_handler))
        .route("/api/control/auth", post(login_handler))
        .route("/api/control/logout", post(logout_handler))
        .route("/api/control/info", get(info_handler))
        .route("/api/control/settings", get(get_settings_handler).post(save_settings_handler))
        .route("/api/control/camera/devices", get(get_camera_devices_handler))
        .route("/api/control/network/apply", post(apply_network_handler))
        .route("/api/control/network/confirm", post(confirm_network_handler))
        .route("/api/control/network/cancel", post(cancel_network_handler))
        .route("/api/control/network/rollback-status", get(network_rollback_status_handler))
        .route("/api/control/security/password", post(change_password_handler))
        .route("/api/control/server/test", post(test_server_handler))
        .route("/api/control/server/repair", post(repair_handler))
        .route("/api/control/system/logs", get(download_logs_handler))
        .route("/api/control/system/backup", get(backup_config_handler))
        .route("/api/control/system/restore", post(restore_config_handler))
        .route("/api/control/system/check-update", post(check_update_handler))
        .route("/api/control/attendance/pending", get(pending_attendance_handler))
        .route("/api/control/health", get(health_handler))
        .route("/api/control/action", post(action_handler))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_validate_settings_success() {
        let s = FullSettings::default();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_settings_rejects_unknown_language() {
        let mut s = FullSettings::default();
        s.general.language = "klingon".to_string();
        assert!(validate_settings(&s).is_err());
    }

    #[test]
    fn test_validate_settings_accepts_auto_language() {
        let mut s = FullSettings::default();
        s.general.language = "auto".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_is_newer_version_semver() {
        assert!(is_newer_version("2.5.0", "2.4.0"));
        assert!(is_newer_version("2.4.1", "2.4.0"));
        assert!(is_newer_version("3.0.0", "2.9.9"));
        assert!(!is_newer_version("2.4.0", "2.4.0"));
        assert!(!is_newer_version("2.3.9", "2.4.0"));
    }

    #[test]
    fn test_is_newer_version_handles_v_prefix_and_short_forms() {
        assert!(is_newer_version("v2.5", "2.4.9"));
        assert!(!is_newer_version("v2.4", "2.4.0"));
        assert!(is_newer_version("2.4.1", "2.4"));
    }

    #[test]
    fn test_run_verified_reports_failure_for_missing_binary() {
        let result = run_verified("smart-absensi-nonexistent-binary-xyz", &[]);
        assert!(result.is_err());
    }

    #[test]
    fn test_validate_settings_fps_and_resolution() {
        let mut s = FullSettings::default();
        s.camera.fps_cap = 2; // Below 5
        assert!(validate_settings(&s).is_err());

        s.camera.fps_cap = 15;
        s.camera.resolution = "invalid_res".to_string();
        assert!(validate_settings(&s).is_err());

        s.camera.resolution = "1280x720".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_static_ip_mode() {
        let mut s = FullSettings::default();
        s.network.mode = "static".to_string();
        s.network.static_ip = "not_an_ip".to_string();
        assert!(validate_settings(&s).is_err());

        s.network.static_ip = "192.168.1.100".to_string();
        s.network.subnet = "255.255.255.0".to_string();
        s.network.gateway = "192.168.1.1".to_string();
        s.network.dns = "1.1.1.1".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_validate_timezone_and_server() {
        let mut s = FullSettings::default();
        s.time.timezone = "Asia/Jakarta; rm -rf /".to_string(); // Injected chars
        assert!(validate_settings(&s).is_err());

        s.time.timezone = "Asia/Jakarta".to_string();
        s.server.url = "ftp://invalid-scheme".to_string();
        assert!(validate_settings(&s).is_err());

        s.server.url = "http://192.168.1.50:8000".to_string();
        assert!(validate_settings(&s).is_ok());
    }

    #[test]
    fn test_preserve_wifi_password_on_save() {
        let temp_dir = std::env::temp_dir().join(format!("edge_test_{}", uuid::Uuid::new_v4()));
        let _ = std::fs::create_dir_all(&temp_dir);
        let settings_path = temp_dir.join("edge_settings.json");

        // 1. Initial save with wifi password
        let mut initial = FullSettings::default();
        initial.network.wifi_password = Some("SuperSecretWifiPass123!".to_string());
        assert!(save_settings_to_file(&settings_path, &initial).is_ok());

        // 2. Read back: wifi_password exists in file
        let loaded1 = load_settings(&settings_path);
        assert_eq!(loaded1.network.wifi_password.as_deref(), Some("SuperSecretWifiPass123!"));

        // 3. New update without wifi_password (None)
        let mut updated = loaded1.clone();
        updated.network.wifi_password = None;
        updated.general.device_name = "Renamed Gate".to_string();
        assert!(save_settings_to_file(&settings_path, &updated).is_ok());

        // 4. Verify wifi password was NOT erased
        let loaded2 = load_settings(&settings_path);
        assert_eq!(loaded2.general.device_name, "Renamed Gate");
        assert_eq!(loaded2.network.wifi_password.as_deref(), Some("SuperSecretWifiPass123!"));

        let _ = std::fs::remove_dir_all(&temp_dir);
    }
}
