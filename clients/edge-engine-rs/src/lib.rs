//! Smart Absensi Edge Engine
//!
//! Runtime edge engine Rust native yang terkonsolidasi:
//! - `core`: database SQLite, outbox absensi, model data, kripto
//! - `vision`: capture kamera V4L2, deteksi wajah (YuNet), pengenalan (SFace/ArcFace), liveness (MiniFASNet)
//! - `server`: streamer MJPEG HTTP Axum dan event vektor overlay SSE
//! - `sync`: worker sinkronisasi outbox dan klien REST API
//! - `hardware`: pembaca serial RFID, jembatan Arduino, GPIO, display framebuffer

pub mod core;
pub mod engine;
pub mod hardware;
pub mod server;
pub mod sync;
pub mod vision;

pub use engine::diagnostics;

