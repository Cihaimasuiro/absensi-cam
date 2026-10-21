use std::time::Instant;

#[derive(Debug, Clone)]
pub struct TrackedStudent {
    pub consecutive_frames: u32,
    pub last_center: (f32, f32),
    pub last_seen: Instant,
}
