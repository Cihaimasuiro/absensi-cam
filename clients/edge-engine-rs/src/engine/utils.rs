use std::path::PathBuf;

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
