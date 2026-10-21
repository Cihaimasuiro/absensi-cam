use anyhow::{Context, Result};
use std::fs;
use std::path::Path;
use std::process::Command;
use tracing::info;

#[derive(Debug, Clone)]
pub struct StorageDoctorReport {
    pub tmp_is_tmpfs: bool,
    pub log_is_tmpfs: bool,
    pub root_is_readonly_or_overlay: bool,
    pub data_mounted: bool,
    pub data_commit_60: bool,
    pub journal_volatile: bool,
}

impl StorageDoctorReport {
    pub fn is_healthy(&self) -> bool {
        self.tmp_is_tmpfs
            && self.log_is_tmpfs
            && self.root_is_readonly_or_overlay
            && self.data_mounted
            && self.journal_volatile
    }
}

/// Audit storage mounts and flash protection status
pub fn check_storage_health() -> StorageDoctorReport {
    let mut report = StorageDoctorReport {
        tmp_is_tmpfs: false,
        log_is_tmpfs: false,
        root_is_readonly_or_overlay: false,
        data_mounted: false,
        data_commit_60: false,
        journal_volatile: false,
    };

    if let Ok(mounts_content) = fs::read_to_string("/proc/mounts") {
        for line in mounts_content.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 4 {
                let mnt_point = parts[1];
                let fs_type = parts[2];
                let opts = parts[3];

                if mnt_point == "/tmp" && fs_type == "tmpfs" {
                    report.tmp_is_tmpfs = true;
                }
                if mnt_point == "/var/log" && fs_type == "tmpfs" {
                    report.log_is_tmpfs = true;
                }
                if mnt_point == "/" && (opts.split(',').any(|o| o == "ro") || fs_type == "overlay") {
                    report.root_is_readonly_or_overlay = true;
                }
                if mnt_point == "/data" {
                    report.data_mounted = true;
                    if opts.contains("commit=60") || fs_type == "f2fs" {
                        report.data_commit_60 = true;
                    }
                }
            }
        }
    }

    if let Ok(journal_conf) = fs::read_to_string("/etc/systemd/journald.conf.d/00-volatile.conf") {
        if journal_conf.contains("Storage=volatile") {
            report.journal_volatile = true;
        }
    }

    report
}

/// Print formatted storage doctor diagnostic report
pub fn run_doctor() -> Result<()> {
    println!("==========================================================");
    println!(" Smart Absensi Edge Storage Doctor                       ");
    println!("==========================================================");

    let report = check_storage_health();

    let mark = |ok: bool| if ok { "[ PASS ]" } else { "[ WARN ]" };

    println!("{} /tmp mounted in RAM (tmpfs): {}", mark(report.tmp_is_tmpfs), report.tmp_is_tmpfs);
    println!("{} /var/log mounted in RAM (tmpfs): {}", mark(report.log_is_tmpfs), report.log_is_tmpfs);
    println!("{} Journald configured volatile: {}", mark(report.journal_volatile), report.journal_volatile);
    println!("{} Root filesystem Read-Only / OverlayFS: {}", mark(report.root_is_readonly_or_overlay), report.root_is_readonly_or_overlay);
    println!("{} Dedicated /data partition mounted: {}", mark(report.data_mounted), report.data_mounted);
    println!("{} /data commit=60 / flash wear-leveling: {}", mark(report.data_commit_60), report.data_commit_60);
    println!("----------------------------------------------------------");

    if report.is_healthy() {
        println!("Overall Status: HEALTHY (Flash memory protected against wear & power cut corruption)");
    } else {
        println!("Overall Status: UNPROTECTED / RUNNING IN DEV MODE");
        println!();
        println!("To protect flash storage on production gate, run:");
        println!("  sudo engine-cli harden-storage");
    }
    println!("==========================================================");

    Ok(())
}

/// Apply Read-Only RootFS, RAM tmpfs mounts, and /data partition configuration
pub fn harden_storage(
    data_mount: &str,
    partition: Option<&str>,
    fs_type: &str,
    dry_run: bool,
) -> Result<()> {
    println!("==========================================================");
    println!(" Smart Absensi Edge Storage Hardening (Native Rust)      ");
    println!("==========================================================");

    if dry_run {
        println!("[DRY RUN MODE] Showing changes without modifying disk.");
    }

    // Step 1: /etc/fstab tmpfs entries
    let fstab_path = Path::new("/etc/fstab");
    if fstab_path.exists() {
        let fstab_content = fs::read_to_string(fstab_path).unwrap_or_default();
        let mut new_entries = Vec::new();

        if !fstab_content.contains("/tmp") {
            new_entries.push("tmpfs /tmp tmpfs defaults,noatime,nosuid,nodev,size=256M 0 0");
        }
        if !fstab_content.contains("/var/tmp") {
            new_entries.push("tmpfs /var/tmp tmpfs defaults,noatime,nosuid,nodev,size=64M 0 0");
        }
        if !fstab_content.contains("/var/log") {
            new_entries.push("tmpfs /var/log tmpfs defaults,noatime,nosuid,nodev,size=64M 0 0");
        }

        if !new_entries.is_empty() {
            println!("Step 1/5: Adding {} tmpfs entries to /etc/fstab...", new_entries.len());
            if !dry_run {
                let backup_path = Path::new("/etc/fstab.bak");
                if !backup_path.exists() {
                    let _ = fs::copy(fstab_path, backup_path);
                }
                let mut updated = fstab_content.clone();
                if !updated.ends_with('\n') {
                    updated.push('\n');
                }
                for entry in &new_entries {
                    updated.push_str(entry);
                    updated.push('\n');
                }
                fs::write(fstab_path, updated).context("Failed to write /etc/fstab")?;
            }
        } else {
            println!("Step 1/5: /etc/fstab tmpfs entries already present.");
        }
    } else {
        println!("Step 1/5: /etc/fstab not found (skipped on non-Linux hosts).");
    }

    // Step 2: Systemd Journald volatile
    let journal_dir = Path::new("/etc/systemd/journald.conf.d");
    let journal_file = journal_dir.join("00-volatile.conf");
    let journal_conf = "[Journal]\nStorage=volatile\nRuntimeMaxUse=32M\nCompress=yes\nForwardToSyslog=no\n";

    println!("Step 2/5: Configuring systemd-journald to volatile storage (RAM)...");
    if !dry_run {
        let _ = fs::create_dir_all(journal_dir);
        let _ = fs::write(&journal_file, journal_conf);
    }

    // Step 3: Setup /data structure and optional partition format
    println!("Step 3/5: Configuring persistent data directory at {}...", data_mount);
    let data_path = Path::new(data_mount);
    if !dry_run {
        let _ = fs::create_dir_all(data_path.join("models"));
        let _ = fs::create_dir_all(data_path.join("config"));
        let db_file = data_path.join("absensi.db");
        if !db_file.exists() {
            let _ = fs::File::create(&db_file);
        }

        // Migrate older files if found
        let old_dir = Path::new("/opt/smart-absensi/edge-engine");
        if old_dir.exists() {
            let old_db = old_dir.join("local_edge.db");
            if old_db.exists() && fs::metadata(&db_file).map(|m| m.len()).unwrap_or(0) == 0 {
                let _ = fs::copy(&old_db, &db_file);
                info!("Migrated legacy database to {:?}", db_file);
            }
            let old_env = old_dir.join(".env");
            let new_env = data_path.join("config").join(".env");
            if old_env.exists() && !new_env.exists() {
                let _ = fs::copy(&old_env, &new_env);
                info!("Migrated legacy .env to {:?}", new_env);
            }
        }
    }

    if let Some(part) = partition {
        println!("Formatting {} as {} (Label: ABSENSI_DATA)...", part, fs_type);
        if !dry_run {
            if fs_type == "f2fs" {
                let _ = Command::new("mkfs.f2fs").args(["-f", "-l", "ABSENSI_DATA", part]).status();
            } else {
                let _ = Command::new("mkfs.ext4").args(["-F", "-L", "ABSENSI_DATA", "-m", "1", part]).status();
            }
        }
    }

    // Step 4: Configure overlayroot (Read-Only Root)
    println!("Step 4/5: Configuring overlayroot for Read-Only RootFS...");
    let overlayroot_conf = "overlayroot=\"tmpfs:swap=0,recurse=0\"\noverlayroot_cfgdisk=\"disabled\"\n";
    if !dry_run {
        // Attempt installing overlayroot if apt is available
        if Path::new("/usr/bin/apt-get").exists() {
            let _ = Command::new("apt-get").args(["update"]).status();
            let _ = Command::new("apt-get").args(["install", "-y", "overlayroot"]).status();
        }
        let _ = fs::write("/etc/overlayroot.conf", overlayroot_conf);
        if Path::new("/usr/sbin/update-initramfs").exists() {
            let _ = Command::new("update-initramfs").arg("-u").status();
        }
    }

    // Step 5: Helper scripts
    println!("Step 5/5: Installing maintenance helper commands (/usr/local/bin/edge-rw)...");
    let edge_rw_content = r#"#!/usr/bin/env bash
if command -v overlayroot-chroot &>/dev/null; then
    echo "=== Entering Root Chroot (Writable Mode) ==="
    exec overlayroot-chroot
else
    mount -o remount,rw /
    echo "Root mounted read-write."
fi
"#;
    if !dry_run {
        let _ = fs::write("/usr/local/bin/edge-rw", edge_rw_content);
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let _ = fs::set_permissions("/usr/local/bin/edge-rw", fs::Permissions::from_mode(0o755));
        }
    }

    println!("----------------------------------------------------------");
    println!("Storage hardening completed successfully.");
    println!("Reboot the device to activate Read-Only RootFS: sudo reboot");
    println!("==========================================================");

    Ok(())
}
