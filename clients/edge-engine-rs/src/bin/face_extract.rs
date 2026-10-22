use clap::Parser;
use smart_absensi_engine::engine::extract::run_extract;
use std::path::PathBuf;

#[derive(Parser, Debug)]
#[command(
    name = "face-extract",
    author = "Smart Absensi Team",
    version = "0.1.0",
    about = "Smart Absensi Server Face Embedding Extraction CLI"
)]
pub struct ExtractCli {
    #[arg(long)]
    pub image: PathBuf,

    #[arg(long)]
    pub models_dir: Option<String>,

    #[arg(long)]
    pub ort_path: Option<String>,
}

fn main() {
    // Arahkan semua log tracing ke stderr agar stdout tetap 100% bersih untuk kontrak JSON
    tracing_subscriber::fmt()
        .with_writer(std::io::stderr)
        .init();

    let cli = ExtractCli::parse();
    let code = run_extract(
        &cli.image,
        cli.models_dir.as_deref(),
        cli.ort_path.as_deref(),
    );
    std::process::exit(code);
}
