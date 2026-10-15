#![allow(clippy::chunks_exact_to_as_chunks)]

pub mod db;
pub mod models;

pub use db::EdgeDb;
pub use models::*;
