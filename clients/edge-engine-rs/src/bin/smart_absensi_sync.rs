//! Smart Absensi Sync Daemon CLI (`smart-absensi-sync`)

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    smart_absensi_engine::engine::run_cli().await
}
