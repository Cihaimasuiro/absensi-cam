use std::env;
use std::fs;
use std::path::{Path, PathBuf};
use std::process::Command;

fn main() {
    println!("cargo:rerun-if-changed=build.rs");

    let target = env::var("TARGET").unwrap_or_default();
    let target_os = env::var("CARGO_CFG_TARGET_OS").unwrap_or_default();

    if target.contains("windows-gnu") || target_os == "windows" {
        copy_mingw_runtime_dlls();
    }
}

fn copy_mingw_runtime_dlls() {
    let out_dir = match env::var("OUT_DIR") {
        Ok(d) => PathBuf::from(d),
        Err(_) => return,
    };

    // OUT_DIR is target/{profile}/build/<package-hash>/out
    let target_dir = match out_dir.ancestors().nth(3) {
        Some(p) => p.to_path_buf(),
        None => return,
    };
    let deps_dir = target_dir.join("deps");
    let _ = fs::create_dir_all(&deps_dir);

    let rustc = env::var("RUSTC").unwrap_or_else(|_| "rustc".to_string());
    let sysroot = match Command::new(&rustc).args(["--print", "sysroot"]).output() {
        Ok(out) if out.status.success() => {
            String::from_utf8_lossy(&out.stdout).trim().to_string()
        }
        _ => {
            if let Ok(home) = env::var("USERPROFILE") {
                PathBuf::from(home)
                    .join(".rustup")
                    .join("toolchains")
                    .join("stable-x86_64-pc-windows-gnu")
                    .to_string_lossy()
                    .to_string()
            } else {
                return;
            }
        }
    };

    let target = env::var("TARGET").unwrap_or_else(|_| "x86_64-pc-windows-gnu".to_string());
    let sysroot_path = Path::new(&sysroot);

    let candidate_dirs = [
        sysroot_path.join("bin"),
        sysroot_path.join("lib").join("rustlib").join(&target).join("bin"),
        sysroot_path.join("lib").join("rustlib").join(&target).join("bin").join("self-contained"),
    ];

    println!("cargo:warning=[build.rs] Checking MinGW runtime DLL co-location...");
    let dll_names = ["libwinpthread-1.dll", "libgcc_s_seh-1.dll"];

    for dll_name in &dll_names {
        let mut found = false;
        for dir in &candidate_dirs {
            let src = dir.join(dll_name);
            if src.exists() {
                if let Err(e) = fs::copy(&src, target_dir.join(dll_name)) {
                    println!("cargo:warning=Failed to copy {:?} to target: {}", src, e);
                }
                if let Err(e) = fs::copy(&src, deps_dir.join(dll_name)) {
                    println!("cargo:warning=Failed to copy {:?} to deps: {}", src, e);
                } else {
                    println!("cargo:warning=Co-located {} to {:?}", dll_name, deps_dir);
                }
                found = true;
                break;
            }
        }
        if !found {
            println!("cargo:warning=Could not find {} in sysroot candidate dirs: {:?}", dll_name, candidate_dirs);
        }
    }

    // Co-locate onnxruntime.dll to deps directory if present in target_dir
    let onnx_src = target_dir.join("onnxruntime.dll");
    if onnx_src.exists() {
        let _ = fs::copy(&onnx_src, deps_dir.join("onnxruntime.dll"));
    }
}
