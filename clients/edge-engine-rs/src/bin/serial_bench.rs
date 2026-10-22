//! Alat Benchmark Serial (SBC -> Arduino Uno)
//! Mengukur throughput riil, latensi round-trip, frame rate, dan stabilitas hardware (DOR0/FE0).

use clap::Parser;
use std::io::{Read, Write};
use std::time::{Duration, Instant};

const SYNC_0: u8 = 0xFF;
const SYNC_1: u8 = 0xAA;
const ACK_BYTE: u8 = 0x06;
const NAK_BYTE: u8 = 0x15;

#[derive(Parser, Debug)]
#[command(name = "serial-bench")]
#[command(about = "Benchmark Serial Link SBC (Orange Pi) <-> Arduino Uno TFT Shield")]
struct Args {
    #[arg(short, long, default_value = "/dev/ttyACM0")]
    port: String,

    #[arg(short, long, default_value_t = 1_000_000)]
    baud: u32,

    #[arg(short, long, default_value_t = 10)]
    duration: u64,

    #[arg(short, long, default_value_t = 2)]
    window: usize,

    #[arg(long, default_value_t = 120)]
    packet_size: usize,

    #[arg(long, default_value = "all")]
    mode: String,
}

#[derive(Default)]
struct BenchStats {
    bytes_sent: u64,
    packets_sent: u64,
    acks_received: u64,
    naks_received: u64,
    timeouts: u64,
    rtt_samples_ms: Vec<f64>,
}

fn build_packet(pkt_type: u8, seq: u8, payload: &[u8]) -> Vec<u8> {
    let len = payload.len().min(160);
    let mut packet = Vec::with_capacity(6 + len);
    packet.push(SYNC_0);
    packet.push(SYNC_1);
    packet.push(pkt_type);
    packet.push(seq);
    packet.push(len as u8);
    packet.extend_from_slice(&payload[..len]);

    let mut chk = pkt_type ^ seq ^ (len as u8);
    for &b in &payload[..len] {
        chk ^= b;
    }
    packet.push(chk);
    packet
}

fn run_throughput_test(port: &mut Box<dyn serialport::SerialPort>, duration_secs: u64) -> (f64, u64) {
    println!("\n=== [1] Raw Throughput Test ({} detik) ===", duration_secs);
    let chunk = [0x55u8; 512];
    let start = Instant::now();
    let duration = Duration::from_secs(duration_secs);
    let mut total_bytes = 0u64;

    while start.elapsed() < duration {
        match port.write(&chunk) {
            Ok(n) => total_bytes += n as u64,
            Err(e) => {
                eprintln!("Write error: {}", e);
                break;
            }
        }
    }
    let _ = port.flush();
    let elapsed = start.elapsed().as_secs_f64();
    let kbps = (total_bytes as f64 / 1024.0) / elapsed;
    println!("Throughput: {:.2} KB/s ({:.2} Mbps)", kbps, (kbps * 8.0) / 1000.0);
    (kbps, total_bytes)
}

fn run_packet_protocol_test(
    port: &mut Box<dyn serialport::SerialPort>,
    duration_secs: u64,
    window_size: usize,
    payload_len: usize,
) -> BenchStats {
    println!(
        "\n=== [2] Micro-Packet Protocol Test (Payload: {}B, Window: {}) ===",
        payload_len, window_size
    );

    let mut stats = BenchStats::default();
    let dummy_payload = vec![0x3Eu8; payload_len];
    let mut seq: u8 = 0;
    let start = Instant::now();
    let duration = Duration::from_secs(duration_secs);

    let mut in_flight_timestamps = std::collections::VecDeque::new();

    while start.elapsed() < duration {
        while in_flight_timestamps.len() < window_size {
            let pkt = build_packet(0x02, seq, &dummy_payload);
            match port.write_all(&pkt) {
                Ok(_) => {
                    stats.bytes_sent += pkt.len() as u64;
                    stats.packets_sent += 1;
                    seq = seq.wrapping_add(1);
                    in_flight_timestamps.push_back(Instant::now());
                }
                Err(e) => {
                    eprintln!("Packet write error: {}", e);
                    break;
                }
            }
        }

        let mut resp = [0u8; 1];
        match port.read_exact(&mut resp) {
            Ok(_) => {
                let send_time = in_flight_timestamps.pop_front().unwrap_or_else(Instant::now);
                let rtt = send_time.elapsed().as_secs_f64() * 1000.0;
                stats.rtt_samples_ms.push(rtt);

                if resp[0] == ACK_BYTE {
                    stats.acks_received += 1;
                } else if resp[0] == NAK_BYTE {
                    stats.naks_received += 1;
                }
            }
            Err(ref e) if e.kind() == std::io::ErrorKind::TimedOut => {
                stats.timeouts += 1;
                in_flight_timestamps.pop_front();
            }
            Err(e) => {
                eprintln!("Packet read error: {}", e);
                break;
            }
        }
    }

    let elapsed = start.elapsed().as_secs_f64();
    let effective_kbps = (stats.bytes_sent as f64 / 1024.0) / elapsed;
    let avg_rtt = if !stats.rtt_samples_ms.is_empty() {
        stats.rtt_samples_ms.iter().sum::<f64>() / stats.rtt_samples_ms.len() as f64
    } else {
        0.0
    };

    println!("Total Terkirim : {} paket ({} byte)", stats.packets_sent, stats.bytes_sent);
    println!("ACK Diterima   : {} ({:.1}%)", stats.acks_received, (stats.acks_received as f64 / stats.packets_sent.max(1) as f64) * 100.0);
    println!("NAK Diterima   : {}", stats.naks_received);
    println!("Timeout        : {}", stats.timeouts);
    println!("Throughput     : {:.2} KB/s", effective_kbps);
    println!("Avg RTT Latency: {:.2} ms", avg_rtt);

    // Estimasi FPS untuk resolusi 160x120 (120 baris) dan 120x90 (90 baris)
    let pps = stats.acks_received as f64 / elapsed;
    println!("Estimasi FPS 160x120 (120 scanlines): {:.1} FPS", pps / 120.0);
    println!("Estimasi FPS 120x90  (90 scanlines) : {:.1} FPS", pps / 90.0);

    stats
}

fn run_abort_latency_test(port: &mut Box<dyn serialport::SerialPort>) {
    println!("\n=== [3] Preemption / STREAM_ABORT Latency Test ===");

    // 1. Kirim beberapa paket biner
    let dummy_payload = [0x55u8; 120];
    let pkt = build_packet(0x02, 1, &dummy_payload);
    let _ = port.write_all(&pkt);

    // 2. Kirim STREAM_ABORT diikuti JSON
    let abort_pkt = build_packet(0x0F, 2, &[]);
    let json_cmd = b"{\"cmd\":\"ping\"}\n";

    let t0 = Instant::now();
    let _ = port.write_all(&abort_pkt);
    let _ = port.write_all(json_cmd);
    let _ = port.flush();

    // 3. Baca respon JSON dari Arduino
    let mut buf = [0u8; 256];
    let mut resp_str = String::new();
    let timeout = Duration::from_millis(500);
    let read_start = Instant::now();

    while read_start.elapsed() < timeout {
        match port.read(&mut buf) {
            Ok(n) if n > 0 => {
                resp_str.push_str(&String::from_utf8_lossy(&buf[..n]));
                if resp_str.contains("pong") || resp_str.contains("ack") || resp_str.contains('\n') {
                    break;
                }
            }
            _ => std::thread::sleep(Duration::from_millis(1)),
        }
    }

    let lat_ms = t0.elapsed().as_secs_f64() * 1000.0;
    println!("Waktu Respon Abort + JSON: {:.2} ms", lat_ms);
    if lat_ms < 50.0 {
        println!("Status: LULUS AC-STREAM-03 (< 50 ms)");
    } else {
        println!("Status: GAGAL AC-STREAM-03 (>= 50 ms)");
    }
}

fn read_telemetry(port: &mut Box<dyn serialport::SerialPort>) {
    println!("\n=== [4] Pembacaan Telemetry Arduino Hardware Register ===");
    let _ = port.write_all(b"{\"cmd\":\"telemetry\"}\n");
    let _ = port.flush();

    std::thread::sleep(Duration::from_millis(100));
    let mut buf = [0u8; 256];
    if let Ok(n) = port.read(&mut buf) {
        let s = String::from_utf8_lossy(&buf[..n]);
        println!("Arduino Telemetry:\n{}", s.trim());
    } else {
        println!("Tidak ada respon telemetry.");
    }
}

fn main() {
    let args = Args::parse();
    println!("Memulai Serial Benchmark di port [{}] @ {} baud", args.port, args.baud);

    let port_builder = serialport::new(&args.port, args.baud).timeout(Duration::from_millis(100));
    let mut port = match port_builder.open() {
        Ok(p) => p,
        Err(e) => {
            eprintln!("Gagal membuka port [{}]: {}", args.port, e);
            std::process::exit(1);
        }
    };

    // Jeda stabilisasi DTR reset Arduino Uno
    println!("Menunggu reset DTR Arduino (2 detik)...");
    std::thread::sleep(Duration::from_secs(2));
    let _ = port.clear(serialport::ClearBuffer::All);

    if args.mode == "throughput" || args.mode == "all" {
        run_throughput_test(&mut port, args.duration.min(5));
    }

    if args.mode == "packets" || args.mode == "all" {
        run_packet_protocol_test(&mut port, args.duration, args.window, args.packet_size);
    }

    if args.mode == "abort" || args.mode == "all" {
        run_abort_latency_test(&mut port);
    }

    read_telemetry(&mut port);
}
