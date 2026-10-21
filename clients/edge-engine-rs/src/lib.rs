//! Smart Absensi Edge Engine
//!
//! Consolidated native Rust edge engine runtime:
//! - `core`: SQLite database, attendance outbox, data models, crypto
//! - `vision`: V4L2 camera capture, face detection (YuNet), recognition (SFace/ArcFace), liveness (MiniFASNet)
//! - `server`: Axum HTTP MJPEG streamer and SSE overlay vector events
//! - `sync`: Outbox synchronization worker and REST API client
//! - `hardware`: RFID serial readers, Arduino bridge, GPIO, framebuffer display

pub mod core;
pub mod engine;
pub mod hardware;
pub mod server;
pub mod sync;
pub mod vision;

