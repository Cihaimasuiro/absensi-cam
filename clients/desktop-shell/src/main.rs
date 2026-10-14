#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::Duration;

#[tauri::command]
fn check_server_health() -> Result<bool, String> {
    let addr = "127.0.0.1:8000"
        .parse()
        .map_err(|e| format!("Alamat host tidak valid: {e}"))?;

    let mut stream = TcpStream::connect_timeout(&addr, Duration::from_millis(1500))
        .map_err(|e| format!("Koneksi ke server gagal: {e}"))?;

    stream
        .set_read_timeout(Some(Duration::from_millis(1500)))
        .map_err(|e| format!("Konfigurasi timeout gagal: {e}"))?;
    stream
        .set_write_timeout(Some(Duration::from_millis(1500)))
        .map_err(|e| format!("Konfigurasi timeout gagal: {e}"))?;

    let request = b"GET /up HTTP/1.1\r\nHost: 127.0.0.1:8000\r\nConnection: close\r\n\r\n";
    stream
        .write_all(request)
        .map_err(|e| format!("Gagal mengirim request HTTP: {e}"))?;

    let mut buffer = [0u8; 512];
    let bytes_read = stream
        .read(&mut buffer)
        .map_err(|e| format!("Gagal membaca respons HTTP: {e}"))?;

    let response = String::from_utf8_lossy(&buffer[..bytes_read]);
    if response.starts_with("HTTP/1.1 200") || response.starts_with("HTTP/1.0 200") {
        Ok(true)
    } else {
        Err("Status server bukan 200 OK".to_string())
    }
}

fn main() {
    if let Err(_err) = tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![check_server_health])
        .run(tauri::generate_context!())
    {
        std::process::exit(1);
    }
}
