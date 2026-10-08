use axum::{response::Html, routing::get, Router};
use std::net::SocketAddr;
use tracing::info;

pub async fn start_stream_server(port: u16) -> anyhow::Result<()> {
    let app = Router::new().route("/", get(|| async { Html("<h1>Smart Absensi Edge Stream</h1>") }));

    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    info!("Stream server mendengarkan di http://{}", addr);

    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;
    Ok(())
}
