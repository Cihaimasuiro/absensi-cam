//! CLI Smart Absensi Edge Engine (`engine-cli`)

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    smart_absensi_engine::engine::run_cli().await
}
