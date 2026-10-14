/// Format elapsed seconds into Bahasa Indonesia relative time string
pub fn format_relative_time_id(seconds: u64) -> String {
    if seconds < 60 {
        format!("{seconds} dtk lalu")
    } else if seconds < 3600 {
        format!("{} mnt lalu", seconds / 60)
    } else if seconds < 86400 {
        format!("{} jam lalu", seconds / 3600)
    } else {
        format!("{} hari lalu", seconds / 86400)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_seconds() {
        assert_eq!(format_relative_time_id(15), "15 dtk lalu");
    }

    #[test]
    fn test_minutes() {
        assert_eq!(format_relative_time_id(120), "2 mnt lalu");
        assert_eq!(format_relative_time_id(3599), "59 mnt lalu");
    }

    #[test]
    fn test_hours() {
        assert_eq!(format_relative_time_id(7200), "2 jam lalu");
    }

    #[test]
    fn test_days() {
        assert_eq!(format_relative_time_id(86400 * 3), "3 hari lalu");
    }
}
