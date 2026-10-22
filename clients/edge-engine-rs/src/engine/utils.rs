use std::path::PathBuf;

/// Resolve model search directories in priority order:
/// 1. Explicit argument (`--models-dir`)
/// 2. Environment variable `SMART_ABSENSI_MODELS_DIR` (or `FACE_MODELS_DIR`)
/// 3. `<executable directory>/models`
/// 4. `./models`
/// 5. `../../models` (dev checkout from engine directory)
/// 6. `/opt/smart-absensi/edge-engine/models`
pub fn model_search_dirs(explicit: Option<&str>) -> Vec<PathBuf> {
    let mut dirs = Vec::new();

    if let Some(exp) = explicit {
        if !exp.trim().is_empty() {
            dirs.push(PathBuf::from(exp));
        }
    }

    if let Ok(env_dir) = std::env::var("SMART_ABSENSI_MODELS_DIR") {
        if !env_dir.trim().is_empty() {
            dirs.push(PathBuf::from(env_dir));
        }
    } else if let Ok(env_dir) = std::env::var("FACE_MODELS_DIR") {
        if !env_dir.trim().is_empty() {
            dirs.push(PathBuf::from(env_dir));
        }
    }

    if let Ok(exe_path) = std::env::current_exe() {
        if let Some(exe_dir) = exe_path.parent() {
            dirs.push(exe_dir.join("models"));
        }
    }

    dirs.push(PathBuf::from("models"));
    dirs.push(PathBuf::from("../../models"));
    dirs.push(PathBuf::from("/opt/smart-absensi/edge-engine/models"));

    dirs
}

pub fn find_model_path(base_dirs: &[PathBuf], candidates: &[&str]) -> Option<PathBuf> {
    for dir in base_dirs {
        for filename in candidates {
            let p = dir.join(filename);
            if p.exists() {
                return Some(p);
            }
        }
    }
    None
}

pub async fn wait_for_shutdown_signal() {
    let ctrl_c = async {
        tokio::signal::ctrl_c()
            .await
            .expect("Failed to install Ctrl+C signal handler");
    };

    #[cfg(unix)]
    let terminate = async {
        match tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()) {
            Ok(mut signal) => {
                signal.recv().await;
            }
            Err(e) => {
                tracing::warn!("Failed to install SIGTERM signal handler: {:#}", e);
                std::future::pending::<()>().await;
            }
        }
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => {
            tracing::info!("Menerima sinyal Ctrl+C (SIGINT), memulai shutdown tertib...");
        },
        _ = terminate => {
            tracing::info!("Menerima sinyal SIGTERM dari systemd, memulai shutdown tertib...");
        },
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_model_search_dirs_explicit_first_and_no_packages_path() {
        let explicit = "/custom/models/path";
        let dirs = model_search_dirs(Some(explicit));

        assert!(!dirs.is_empty());
        assert_eq!(dirs[0], PathBuf::from(explicit), "Explicit path must be first");

        for dir in &dirs {
            let s = dir.to_string_lossy();
            assert!(
                !s.contains("packages"),
                "Model search path must not contain legacy 'packages' path: {:?}",
                dir
            );
            assert_ne!(
                dir,
                &PathBuf::from("."),
                "Model search path must drop naked '.' search directory"
            );
        }
    }

    #[test]
    fn test_model_search_dirs_none_explicit() {
        let dirs = model_search_dirs(None);
        assert!(!dirs.is_empty());
        assert!(dirs.contains(&PathBuf::from("models")));
        assert!(dirs.contains(&PathBuf::from("../../models")));
        assert!(dirs.contains(&PathBuf::from("/opt/smart-absensi/edge-engine/models")));
    }
}

