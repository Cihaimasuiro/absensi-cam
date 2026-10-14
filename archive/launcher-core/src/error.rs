use std::fmt;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ApiError {
    Network(String),
    Timeout,
    Unauthorized,
    NotFound(String),
    Server(u16),
}

impl fmt::Display for ApiError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Network(msg) => write!(f, "Kesalahan jaringan: {msg}"),
            Self::Timeout => write!(f, "Koneksi batas waktu (timeout)"),
            Self::Unauthorized => write!(f, "Otorisasi ditolak (unauthorized)"),
            Self::NotFound(id) => write!(f, "Perangkat '{id}' tidak ditemukan"),
            Self::Server(code) => write!(f, "Server mengembalikan status {code}"),
        }
    }
}

impl std::error::Error for ApiError {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_api_error_display() {
        assert_eq!(
            ApiError::Network("DNS gagal".into()).to_string(),
            "Kesalahan jaringan: DNS gagal"
        );
        assert_eq!(
            ApiError::Timeout.to_string(),
            "Koneksi batas waktu (timeout)"
        );
        assert_eq!(
            ApiError::Unauthorized.to_string(),
            "Otorisasi ditolak (unauthorized)"
        );
        assert_eq!(
            ApiError::NotFound("dev-99".into()).to_string(),
            "Perangkat 'dev-99' tidak ditemukan"
        );
        assert_eq!(
            ApiError::Server(500).to_string(),
            "Server mengembalikan status 500"
        );
    }
}
