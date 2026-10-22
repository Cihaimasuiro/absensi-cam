use std::path::PathBuf;

pub fn find_model(names: &[&str]) -> Option<PathBuf> {
    let manifest = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let dirs = [
        manifest.join("../../models"),
        manifest.join("../../../models"),
        manifest.join("models"),
        manifest.join("target/aarch64-unknown-linux-gnu/release"),
        manifest.join("../../target/aarch64-unknown-linux-gnu/release"),
    ];

    for dir in &dirs {
        for name in names {
            let p = dir.join(name);
            if p.exists() {
                return Some(p);
            }
        }
    }
    None
}
