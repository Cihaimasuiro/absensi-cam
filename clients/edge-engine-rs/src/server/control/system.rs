use std::path::Path;

#[derive(Debug, Default)]
pub struct MemoryInfo {
    pub total_mb: i64,
    pub free_mb: i64,
    pub used_mb: i64,
    pub usage_pct: f64,
}

#[derive(Debug, Default)]
pub struct DiskInfo {
    pub total_mb: i64,
    pub free_mb: i64,
    pub used_mb: i64,
    pub usage_pct: f64,
}

#[derive(Debug, Default)]
pub struct NetworkStats {
    pub interface: String,
    pub ip: String,
    pub mac: String,
    pub rx_bytes: u64,
    pub tx_bytes: u64,
}

pub fn read_soc_temp() -> f64 {
    for p in &[
        "/sys/class/thermal/thermal_zone0/temp",
        "/sys/devices/virtual/thermal/thermal_zone0/temp",
    ] {
        if let Ok(c) = std::fs::read_to_string(p) {
            if let Ok(milli) = c.trim().parse::<f64>() {
                return (milli / 1000.0 * 10.0).round() / 10.0;
            }
        }
    }
    0.0
}

/// Runs a command and reports whether it actually succeeded.
/// Replaces fire-and-forget calls that always reported success.
pub fn run_verified(program: &str, args: &[&str]) -> Result<String, String> {
    match std::process::Command::new(program).args(args).output() {
        Ok(out) if out.status.success() => {
            let stdout = String::from_utf8_lossy(&out.stdout).trim().to_string();
            Ok(stdout)
        }
        Ok(out) => {
            let stderr = String::from_utf8_lossy(&out.stderr).trim().to_string();
            Err(format!(
                "{} exited with status {}{}",
                program,
                out.status.code().unwrap_or(-1),
                if stderr.is_empty() { String::new() } else { format!(": {}", stderr) }
            ))
        }
        Err(e) => Err(format!("Cannot execute {}: {}", program, e)),
    }
}

/// Compares two dotted version strings. Returns true when `candidate` is
/// strictly newer than `current`. Non-numeric segments compare as strings.
pub fn is_newer_version(candidate: &str, current: &str) -> bool {
    let parse = |v: &str| -> Vec<String> {
        v.trim()
            .trim_start_matches('v')
            .split('.')
            .map(|s| s.to_string())
            .collect()
    };
    let a = parse(candidate);
    let b = parse(current);
    let len = a.len().max(b.len());
    for i in 0..len {
        let x = a.get(i).cloned().unwrap_or_else(|| "0".to_string());
        let y = b.get(i).cloned().unwrap_or_else(|| "0".to_string());
        match (x.parse::<u64>(), y.parse::<u64>()) {
            (Ok(nx), Ok(ny)) => {
                if nx != ny {
                    return nx > ny;
                }
            }
            _ => {
                if x != y {
                    return x > y;
                }
            }
        }
    }
    false
}

pub fn read_memory_info() -> MemoryInfo {
    let mut total_kb = 0i64;
    let mut avail_kb = 0i64;

    if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
        for line in c.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() < 2 {
                continue;
            }
            let val = parts[1].parse::<i64>().unwrap_or(0);
            match parts[0] {
                "MemTotal:" => total_kb = val,
                "MemAvailable:" => avail_kb = val,
                _ => {}
            }
        }
    }

    if avail_kb == 0 {
        // Fallback for kernels without MemAvailable
        if let Ok(c) = std::fs::read_to_string("/proc/meminfo") {
            for line in c.lines() {
                if line.starts_with("MemFree:") {
                    let parts: Vec<&str> = line.split_whitespace().collect();
                    if parts.len() >= 2 {
                        avail_kb = parts[1].parse::<i64>().unwrap_or(0);
                    }
                    break;
                }
            }
        }
    }

    let total_mb = total_kb / 1024;
    let free_mb = avail_kb / 1024;
    let used_mb = (total_mb - free_mb).max(0);
    let usage_pct = if total_mb > 0 {
        (used_mb as f64 / total_mb as f64 * 100.0 * 10.0).round() / 10.0
    } else {
        0.0
    };

    MemoryInfo { total_mb, free_mb, used_mb, usage_pct }
}

pub fn read_disk_info() -> DiskInfo {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        use std::mem::MaybeUninit;
        let mount = if Path::new("/data").exists() { "/data" } else { "/" };
        let path = CString::new(mount).unwrap_or_else(|_| CString::new("/").unwrap());
        let mut stat: MaybeUninit<libc::statvfs> = MaybeUninit::uninit();
        // SAFETY: statvfs writes to valid path pointer and uninit statvfs buffer.
        let ok = unsafe { libc::statvfs(path.as_ptr(), stat.as_mut_ptr()) == 0 };
        if ok {
            // SAFETY: statvfs returned 0 (success), meaning stat is fully populated.
            let s = unsafe { stat.assume_init() };
            // Note: statvfs fields (f_frsize, f_blocks, f_bavail) are u32 on 32-bit architectures (e.g. armv7),
            // so u64 casts are required for cross-platform portability.
            #[allow(clippy::unnecessary_cast)]
            let frsize = s.f_frsize as u64;
            #[allow(clippy::unnecessary_cast)]
            let total_mb = ((s.f_blocks as u64) * frsize / (1024 * 1024)) as i64;
            #[allow(clippy::unnecessary_cast)]
            let free_mb = ((s.f_bavail as u64) * frsize / (1024 * 1024)) as i64;
            let used_mb = (total_mb - free_mb).max(0);
            let usage_pct = if total_mb > 0 {
                (used_mb as f64 / total_mb as f64 * 100.0 * 10.0).round() / 10.0
            } else {
                0.0
            };
            return DiskInfo { total_mb, free_mb, used_mb, usage_pct };
        }
    }
    DiskInfo { total_mb: 0, free_mb: 0, used_mb: 0, usage_pct: 0.0 }
}

pub fn read_load_average() -> (f64, f64, f64) {
    if let Ok(c) = std::fs::read_to_string("/proc/loadavg") {
        let parts: Vec<&str> = c.split_whitespace().collect();
        if parts.len() >= 3 {
            return (
                parts[0].parse().unwrap_or(0.0),
                parts[1].parse().unwrap_or(0.0),
                parts[2].parse().unwrap_or(0.0),
            );
        }
    }
    (0.0, 0.0, 0.0)
}

/// CPU usage sampled over 200ms from /proc/stat deltas.
pub fn read_cpu_usage_pct() -> f64 {
    fn sample() -> Option<(u64, u64)> {
        let c = std::fs::read_to_string("/proc/stat").ok()?;
        let line = c.lines().next()?;
        let parts: Vec<&str> = line.split_whitespace().collect();
        if parts.len() < 5 {
            return None;
        }
        let vals: Vec<u64> = parts[1..].iter().filter_map(|v| v.parse::<u64>().ok()).collect();
        if vals.len() < 4 {
            return None;
        }
        let idle = vals[3] + vals.get(4).copied().unwrap_or(0);
        let total: u64 = vals.iter().sum();
        Some((idle, total))
    }

    let Some((idle1, total1)) = sample() else { return 0.0 };
    std::thread::sleep(std::time::Duration::from_millis(200));
    let Some((idle2, total2)) = sample() else { return 0.0 };

    let total_delta = total2.saturating_sub(total1);
    if total_delta == 0 {
        return 0.0;
    }
    let idle_delta = idle2.saturating_sub(idle1);
    let busy = total_delta.saturating_sub(idle_delta);
    (busy as f64 / total_delta as f64 * 100.0 * 10.0).round() / 10.0
}

pub fn read_cpu_model() -> String {
    if let Ok(c) = std::fs::read_to_string("/proc/cpuinfo") {
        for line in c.lines() {
            if line.starts_with("model name") || line.starts_with("Model") || line.starts_with("Hardware") {
                if let Some((_, v)) = line.split_once(':') {
                    let v = v.trim();
                    if !v.is_empty() {
                        return v.to_string();
                    }
                }
            }
        }
    }
    "Unknown CPU".to_string()
}

/// (RSS in MB, CPU percent) for the current process.
pub fn read_process_stats() -> (i64, f64) {
    let pid = std::process::id();
    let mut rss_mb = 0i64;
    let mut cpu_pct = 0.0;

    if let Ok(c) = std::fs::read_to_string(format!("/proc/{}/status", pid)) {
        for line in c.lines() {
            if line.starts_with("VmRSS:") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 2 {
                    let kb = parts[1].parse::<i64>().unwrap_or(0);
                    rss_mb = kb / 1024;
                }
            }
        }
    }

    if let Ok(c) = std::fs::read_to_string(format!("/proc/{}/stat", pid)) {
        // Fields after the comm field (which may contain spaces, so split on last ')')
        if let Some(idx) = c.rfind(')') {
            let rest: Vec<&str> = c[idx + 1..].split_whitespace().collect();
            // utime=14, stime=15 (1-indexed incl. pid+comm) => rest[11], rest[12]
            if rest.len() > 12 {
                let utime = rest[11].parse::<f64>().unwrap_or(0.0);
                let stime = rest[12].parse::<f64>().unwrap_or(0.0);
                let ticks = utime + stime;
                let hz = 100.0_f64; // USER_HZ is 100 on Linux
                let elapsed = read_system_uptime_sec().max(1) as f64;
                cpu_pct = (ticks / hz / elapsed * 100.0 * 10.0).round() / 10.0;
            }
        }
    }

    (rss_mb, cpu_pct)
}

pub fn read_network_stats(preferred: &str) -> NetworkStats {
    let mut iface = preferred.trim().to_string();
    if iface.is_empty() || !Path::new(&format!("/sys/class/net/{}", iface)).exists() {
        // Pick the first non-loopback interface with an IPv4 address
        if let Ok(entries) = std::fs::read_dir("/sys/class/net") {
            for e in entries.flatten() {
                let name = e.file_name().to_string_lossy().to_string();
                if name != "lo" {
                    iface = name;
                    break;
                }
            }
        }
    }

    let mut rx_bytes = 0u64;
    let mut tx_bytes = 0u64;
    if let Ok(c) = std::fs::read_to_string("/proc/net/dev") {
        for line in c.lines().skip(2) {
            if let Some((name, rest)) = line.split_once(':') {
                if name.trim() == iface {
                    let fields: Vec<&str> = rest.split_whitespace().collect();
                    if fields.len() >= 9 {
                        rx_bytes = fields[0].parse().unwrap_or(0);
                        tx_bytes = fields[8].parse().unwrap_or(0);
                    }
                    break;
                }
            }
        }
    }

    let mac = std::fs::read_to_string(format!("/sys/class/net/{}/address", iface))
        .map(|s| s.trim().to_string())
        .unwrap_or_else(|_| "—".to_string());

    let ip = read_interface_ip(&iface);

    NetworkStats { interface: iface, ip, mac, rx_bytes, tx_bytes }
}

#[cfg_attr(not(unix), allow(unused_variables))]
pub fn read_interface_ip(iface: &str) -> String {
    #[cfg(unix)]
    {
        use std::ffi::CString;
        let Ok(name) = CString::new(iface) else { return "—".to_string() };
        let bytes = name.as_bytes_with_nul();
        if bytes.len() > 16 {
            return "—".to_string();
        }

        // SAFETY: Invokes socket(AF_INET, SOCK_DGRAM) and ioctl(SIOCGIFADDR) to query interface IP.
        // File descriptor is closed before returning.
        unsafe {
            let mut ifr: libc::ifreq = std::mem::zeroed();
            for (i, b) in bytes.iter().enumerate() {
                ifr.ifr_name[i] = *b as libc::c_char;
            }
            let sock = libc::socket(libc::AF_INET, libc::SOCK_DGRAM, 0);
            if sock < 0 {
                return "—".to_string();
            }
            let rc = libc::ioctl(sock, libc::SIOCGIFADDR, &mut ifr);
            libc::close(sock);
            if rc == 0 {
                let sin = &*(&ifr.ifr_ifru.ifru_addr as *const libc::sockaddr as *const libc::sockaddr_in);
                let octets = sin.sin_addr.s_addr.to_ne_bytes();
                return format!("{}.{}.{}.{}", octets[0], octets[1], octets[2], octets[3]);
            }
        }
    }
    "—".to_string()
}

pub fn read_os_name() -> String {
    if let Ok(c) = std::fs::read_to_string("/etc/os-release") {
        for line in c.lines() {
            if let Some(v) = line.strip_prefix("PRETTY_NAME=") {
                return v.trim_matches('"').to_string();
            }
        }
    }
    std::env::consts::OS.to_string()
}

pub fn read_kernel_version() -> String {
    std::fs::read_to_string("/proc/sys/kernel/osrelease")
        .map(|s| s.trim().to_string())
        .unwrap_or_else(|_| String::new())
}

pub fn read_system_uptime_sec() -> u64 {
    std::fs::read_to_string("/proc/uptime")
        .ok()
        .and_then(|c| c.split_whitespace().next().and_then(|v| v.parse::<f64>().ok()))
        .map(|v| v as u64)
        .unwrap_or(0)
}

pub fn read_wifi_ssid() -> Option<String> {
    let out = std::process::Command::new("iwgetid").arg("-r").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout).trim().to_string();
    if s.is_empty() { None } else { Some(s) }
}

pub fn read_wifi_signal_dbm() -> Option<i32> {
    let c = std::fs::read_to_string("/proc/net/wireless").ok()?;
    for line in c.lines().skip(2) {
        if let Some((_, rest)) = line.split_once(':') {
            let fields: Vec<&str> = rest.split_whitespace().collect();
            if fields.len() >= 3 {
                let raw = fields[2].trim_end_matches('.').parse::<f64>().ok()?;
                return Some(raw.round() as i32);
            }
        }
    }
    None
}

/// Reads the Pi firmware throttle flags. Returns None when not a Raspberry Pi.
pub fn read_throttled_status() -> Option<String> {
    let out = std::process::Command::new("vcgencmd").arg("get_throttled").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout);
    let hex = s.trim().strip_prefix("throttled=")?;
    let flags = u32::from_str_radix(hex.trim_start_matches("0x"), 16).ok()?;

    if flags == 0 {
        return Some("OK".to_string());
    }

    let mut issues = Vec::new();
    if flags & 0x1 != 0 { issues.push("under-voltage"); }
    if flags & 0x2 != 0 { issues.push("arm-frequency-capped"); }
    if flags & 0x4 != 0 { issues.push("throttled"); }
    if flags & 0x8 != 0 { issues.push("soft-temp-limit"); }
    if flags & 0x10000 != 0 { issues.push("under-voltage-occurred"); }
    if flags & 0x20000 != 0 { issues.push("arm-frequency-capped-occurred"); }
    if flags & 0x40000 != 0 { issues.push("throttled-occurred"); }
    if flags & 0x80000 != 0 { issues.push("soft-temp-limit-occurred"); }

    Some(issues.join(", "))
}

pub fn read_power_volts() -> Option<f64> {
    let out = std::process::Command::new("vcgencmd").arg("measure_volts").output().ok()?;
    if !out.status.success() {
        return None;
    }
    let s = String::from_utf8_lossy(&out.stdout);
    let v = s.trim().strip_prefix("volt=")?.trim_end_matches('V');
    v.parse::<f64>().ok()
}

pub fn read_outbox_bytes(db_path: &str) -> i64 {
    let dir = Path::new(db_path).parent().unwrap_or(Path::new("."));
    let mut total = 0i64;
    for suffix in ["", "-wal", "-shm"] {
        let p = dir.join(format!(
            "{}{}",
            Path::new(db_path).file_name().map(|f| f.to_string_lossy().to_string()).unwrap_or_default(),
            suffix
        ));
        if let Ok(m) = std::fs::metadata(&p) {
            total += m.len() as i64;
        }
    }
    total
}

pub fn read_db_size_mb(db_path: &str) -> f64 {
    std::fs::metadata(db_path)
        .map(|m| (m.len() as f64 / (1024.0 * 1024.0) * 10.0).round() / 10.0)
        .unwrap_or(0.0)
}

pub async fn read_last_sync_at(db_path: &str) -> Option<String> {
    if !Path::new(db_path).exists() {
        return None;
    }
    let db = libsql::Builder::new_local(db_path).build().await.ok()?;
    let conn = db.connect().ok()?;
    let mut rows = conn
        .query(
            "SELECT MAX(synced_at) FROM outbox_attendance WHERE synced = 1",
            (),
        )
        .await
        .ok()?;
    if let Ok(Some(row)) = rows.next().await {
        if let Ok(Some(v)) = row.get::<Option<String>>(0) {
            return Some(v);
        }
    }
    None
}

pub fn get_local_ip() -> String {
    if let Ok(sock) = std::net::UdpSocket::bind("0.0.0.0:0") {
        if sock.connect("1.1.1.1:80").is_ok() {
            if let Ok(addr) = sock.local_addr() {
                let ip = addr.ip().to_string();
                if ip != "0.0.0.0" && ip != "127.0.0.1" {
                    return ip;
                }
            }
        }
    }
    "127.0.0.1".to_string()
}

pub fn get_hostname() -> String {
    if let Ok(c) = std::fs::read_to_string("/etc/hostname") {
        let trimmed = c.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }
    "smart-absensi-gate".to_string()
}

pub async fn query_db_counts(db_path: &str) -> (i64, i64, i64) {
    if !Path::new(db_path).exists() {
        return (0, 0, 0);
    }

    if let Ok(db) = libsql::Builder::new_local(db_path).build().await {
        if let Ok(conn) = db.connect() {
            let mut templates_cnt = 0i64;
            let mut outbox_cnt = 0i64;
            let mut quarantined_cnt = 0i64;

            if let Ok(mut rows) = conn.query("SELECT COUNT(*) FROM templates", ()).await {
                if let Ok(Some(row)) = rows.next().await {
                    if let Ok(cnt) = row.get::<i64>(0) {
                        templates_cnt = cnt;
                    }
                }
            }

            if let Ok(mut rows) = conn.query("SELECT COUNT(*) FROM outbox_attendance WHERE synced = 0", ()).await {
                if let Ok(Some(row)) = rows.next().await {
                    if let Ok(cnt) = row.get::<i64>(0) {
                        outbox_cnt = cnt;
                    }
                }
            }

            if let Ok(mut rows) = conn.query("SELECT COUNT(*) FROM quarantined_templates", ()).await {
                if let Ok(Some(row)) = rows.next().await {
                    if let Ok(cnt) = row.get::<i64>(0) {
                        quarantined_cnt = cnt;
                    }
                }
            }

            return (templates_cnt, outbox_cnt, quarantined_cnt);
        }
    }
    (0, 0, 0)
}
