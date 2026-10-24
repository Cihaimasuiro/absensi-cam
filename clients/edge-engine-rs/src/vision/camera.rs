//! Modul Capture Kamera V4L2 libc Murni
//!
//! Dibatasi dengan #[cfg(target_os = "linux")].
//! Memakai libc::ioctl native dengan struct #[repr(C)] dan assertion ukuran saat kompilasi.
//! Mendukung format MJPEG dan YUYV 4:2:2. Capture berjalan pada thread khusus dengan buffer mmap.

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PixelFormat {
    Mjpeg,
    Yuyv,
}

#[cfg(target_os = "linux")]
pub mod linux {
    use anyhow::{bail, Result};
    use std::ffi::CString;
    use std::os::unix::io::RawFd;
    use std::ptr;
    use std::sync::atomic::{AtomicBool, Ordering};
    use std::sync::Arc;
    use std::time::Duration;
    use tracing::{error, info, warn};
    use crate::vision::{encode_rgb_to_jpeg_into, yuyv_to_rgb_stride};
    pub use super::PixelFormat;

    // ── Konstanta V4L2 ──────────────────────────────────────────────────────────
    pub const V4L2_BUF_TYPE_VIDEO_CAPTURE: u32 = 1;
    pub const V4L2_MEMORY_MMAP: u32 = 1;
    pub const V4L2_FIELD_ANY: u32 = 0;
    pub const V4L2_PIX_FMT_MJPEG: u32 = 0x47504a4d; // 'MJPG'
    pub const V4L2_PIX_FMT_YUYV: u32 = 0x56595559;  // 'YUYV'
    pub const V4L2_BUF_FLAG_ERROR: u32 = 0x00000040;

    // Definisi magic ioctl Linux:
    // Konstanta dengan ukuran payload tetap sama pada 32-bit dan 64-bit:
    pub const VIDIOC_REQBUFS: libc::c_ulong = 0xc0145608;
    pub const VIDIOC_G_FMT: libc::c_ulong = 0xc0d05604;
    pub const VIDIOC_S_FMT: libc::c_ulong = 0xc0d05605;
    pub const VIDIOC_STREAMON: libc::c_ulong = 0x40045612;
    pub const VIDIOC_STREAMOFF: libc::c_ulong = 0x40045613;

    // Konstanta yang membawa struct v4l2_buffer:
    // 64-bit: size 88 byte (_IOC_SIZE 0x58) -> 0xc0585609 / 0xc058560f / 0xc0585611
    // 32-bit: size 68 byte (_IOC_SIZE 0x44) -> 0xc0445609 / 0xc044560f / 0xc0445611
    #[cfg(target_pointer_width = "64")]
    pub const VIDIOC_QUERYBUF: libc::c_ulong = 0xc0585609;
    #[cfg(target_pointer_width = "64")]
    pub const VIDIOC_QBUF: libc::c_ulong = 0xc058560f;
    #[cfg(target_pointer_width = "64")]
    pub const VIDIOC_DQBUF: libc::c_ulong = 0xc0585611;

    #[cfg(target_pointer_width = "32")]
    pub const VIDIOC_QUERYBUF: libc::c_ulong = 0xc0445609;
    #[cfg(target_pointer_width = "32")]
    pub const VIDIOC_QBUF: libc::c_ulong = 0xc044560f;
    #[cfg(target_pointer_width = "32")]
    pub const VIDIOC_DQBUF: libc::c_ulong = 0xc0445611;

    // ── Layout Struct V4L2 (Arsitektur 64-bit & 32-bit) ───────────────────────

    #[repr(C)]
    #[derive(Copy, Clone, Default)]
    pub struct V4l2Requestbuffers {
        pub count: u32,
        pub buf_type: u32,
        pub memory: u32,
        pub capabilities: u32,
        pub flags: u8,
        pub reserved: [u8; 3],
    }

    #[repr(C)]
    #[derive(Copy, Clone, Default)]
    pub struct V4l2PixFormat {
        pub width: u32,
        pub height: u32,
        pub pixelformat: u32,
        pub field: u32,
        pub bytesperline: u32,
        pub sizeimage: u32,
        pub colorspace: u32,
        pub priv_flags: u32,
        pub flags: u32,
        pub ycbcr_enc: u32,
        pub quantization: u32,
        pub xfer_func: u32,
    }

    #[repr(C)]
    #[derive(Copy, Clone)]
    pub struct V4l2Format {
        pub buf_type: u32,
        pub _pad: u32,
        pub raw: [u8; 200],
    }

    impl Default for V4l2Format {
        fn default() -> Self {
            Self {
                buf_type: 0,
                _pad: 0,
                raw: [0u8; 200],
            }
        }
    }

    impl V4l2Format {
        pub fn pix(&self) -> &V4l2PixFormat {
            // SAFETY: buffer mentah V4l2Format berukuran 200 byte, melebihi 48 byte milik V4l2PixFormat.
            unsafe { &*(self.raw.as_ptr() as *const V4l2PixFormat) }
        }

        pub fn pix_mut(&mut self) -> &mut V4l2PixFormat {
            // SAFETY: buffer mentah V4l2Format berukuran 200 byte, melebihi 48 byte milik V4l2PixFormat.
            unsafe { &mut *(self.raw.as_mut_ptr() as *mut V4l2PixFormat) }
        }
    }

    #[repr(C)]
    #[derive(Copy, Clone, Default)]
    pub struct V4l2Timeval {
        #[cfg(target_pointer_width = "64")]
        pub tv_sec: i64,
        #[cfg(target_pointer_width = "64")]
        pub tv_usec: i64,

        #[cfg(target_pointer_width = "32")]
        pub tv_sec: i32,
        #[cfg(target_pointer_width = "32")]
        pub tv_usec: i32,
    }

    #[cfg(target_pointer_width = "64")]
    #[repr(C)]
    #[derive(Copy, Clone, Default)]
    pub struct V4l2Buffer {
        pub index: u32,
        pub buf_type: u32,
        pub bytesused: u32,
        pub flags: u32,
        pub field: u32,
        pub _pad0: u32,
        pub timestamp: V4l2Timeval,
        pub timecode: [u8; 16],
        pub sequence: u32,
        pub memory: u32,
        pub m_offset: u64,
        pub length: u32,
        pub reserved2: u32,
        pub request_fd: i32,
        pub _pad1: u32,
    }

    #[cfg(target_pointer_width = "32")]
    #[repr(C)]
    #[derive(Copy, Clone, Default)]
    pub struct V4l2Buffer {
        pub index: u32,
        pub buf_type: u32,
        pub bytesused: u32,
        pub flags: u32,
        pub field: u32,
        pub timestamp: V4l2Timeval,
        pub timecode: [u8; 16],
        pub sequence: u32,
        pub memory: u32,
        pub m_offset: u32,
        pub length: u32,
        pub reserved2: u32,
        pub request_fd: i32,
    }

    // ── Assertion Layout Saat Kompilasi ──────────────────────────────────
    const _: () = assert!(std::mem::size_of::<V4l2Requestbuffers>() == 20, "V4l2Requestbuffers must be 20 bytes");
    const _: () = assert!(std::mem::size_of::<V4l2Format>() == 208, "V4l2Format must be 208 bytes");

    #[cfg(target_pointer_width = "64")]
    const _: () = assert!(std::mem::size_of::<V4l2Buffer>() == 88, "V4l2Buffer must be 88 bytes on 64-bit");

    #[cfg(target_pointer_width = "32")]
    const _: () = assert!(std::mem::size_of::<V4l2Buffer>() == 68, "V4l2Buffer must be 68 bytes on 32-bit");

    /// Helper ioctl terbungkus yang menangani retry EINTR.
    ///
    /// # Safety
    /// Pemanggil wajib menjamin `fd` adalah file descriptor yang terbuka dan valid, serta `arg`
    /// menunjuk ke memori valid dengan ukuran/alignment tepat untuk `request` ioctl tersebut.
    #[inline]
    unsafe fn xioctl<T>(fd: RawFd, request: libc::c_ulong, arg: &mut T) -> std::io::Result<i32> {
        loop {
            let ret = libc::ioctl(fd, request, arg as *mut T as *mut libc::c_void);
            if ret == -1 {
                let err = std::io::Error::last_os_error();
                if err.raw_os_error() == Some(libc::EINTR) {
                    continue;
                }
                return Err(err);
            }
            return Ok(ret);
        }
    }

    struct MmapBuffer {
        ptr: *mut libc::c_void,
        len: usize,
    }

    pub struct Camera {
        fd: RawFd,
        buffers: Vec<MmapBuffer>,
        pub width: u32,
        pub height: u32,
        pub bytesperline: u32,
        pub pixel_format: PixelFormat,
    }

    impl Camera {
        pub fn open(device_path: &str, width: u32, height: u32) -> Result<Self> {
            Self::open_with_format(device_path, width, height, false)
        }

        pub fn open_with_format(device_path: &str, width: u32, height: u32, prefer_yuyv: bool) -> Result<Self> {
            let c_path = CString::new(device_path)?;
            let fd = unsafe { libc::open(c_path.as_ptr(), libc::O_RDWR | libc::O_NONBLOCK, 0) };
            if fd < 0 {
                bail!("Failed to open video device {}: errno {}", device_path, std::io::Error::last_os_error());
            }

            let mut cam = Camera {
                fd,
                buffers: Vec::new(),
                width,
                height,
                bytesperline: width * 2,
                pixel_format: PixelFormat::Mjpeg,
            };

            // Jika init() gagal, Drop untuk Camera akan menutup self.fd dengan bersih (tanpa double-close)
            cam.init(prefer_yuyv)?;

            Ok(cam)
        }

        fn init(&mut self, prefer_yuyv: bool) -> Result<()> {
            let (primary_fmt, fallback_fmt, primary_pixel, fallback_pixel) = if prefer_yuyv {
                (V4L2_PIX_FMT_YUYV, V4L2_PIX_FMT_MJPEG, PixelFormat::Yuyv, PixelFormat::Mjpeg)
            } else {
                (V4L2_PIX_FMT_MJPEG, V4L2_PIX_FMT_YUYV, PixelFormat::Mjpeg, PixelFormat::Yuyv)
            };

            let mut fmt = V4l2Format {
                buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                ..Default::default()
            };
            {
                let pix = fmt.pix_mut();
                pix.width = self.width;
                pix.height = self.height;
                pix.pixelformat = primary_fmt;
                pix.field = V4L2_FIELD_ANY;
            }

            let s_fmt_res = unsafe { xioctl(self.fd, VIDIOC_S_FMT, &mut fmt) };
            let mut configured_format = if s_fmt_res.is_ok() && fmt.pix().pixelformat == primary_fmt {
                Some(primary_pixel)
            } else {
                None
            };

            if configured_format.is_none() {
                let mut alt_fmt = V4l2Format {
                    buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                    ..Default::default()
                };
                {
                    let pix = alt_fmt.pix_mut();
                    pix.width = self.width;
                    pix.height = self.height;
                    pix.pixelformat = fallback_fmt;
                    pix.field = V4L2_FIELD_ANY;
                }
                let alt_res = unsafe { xioctl(self.fd, VIDIOC_S_FMT, &mut alt_fmt) };
                if alt_res.is_ok() && alt_fmt.pix().pixelformat == fallback_fmt {
                    fmt = alt_fmt;
                    configured_format = Some(fallback_pixel);
                }
            }

            // Jika keduanya tidak terkonfirmasi lewat S_FMT, tanyakan format yang benar-benar dipakai driver
            if configured_format.is_none() {
                let mut get_fmt = V4l2Format {
                    buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                    ..Default::default()
                };
                if unsafe { xioctl(self.fd, VIDIOC_G_FMT, &mut get_fmt) }.is_ok() {
                    fmt = get_fmt;
                    let fourcc = fmt.pix().pixelformat;
                    if fourcc == V4L2_PIX_FMT_MJPEG {
                        configured_format = Some(PixelFormat::Mjpeg);
                    } else if fourcc == V4L2_PIX_FMT_YUYV {
                        configured_format = Some(PixelFormat::Yuyv);
                    }
                }
            }

            let actual_pix = fmt.pix();
            self.pixel_format = match configured_format {
                Some(f) => f,
                None => {
                    let fourcc = actual_pix.pixelformat.to_le_bytes();
                    bail!(
                        "Driver rejected both MJPG and YUYV. Negotiated fourcc: '{}{}{}{}' (0x{:08x})",
                        fourcc[0] as char, fourcc[1] as char, fourcc[2] as char, fourcc[3] as char, actual_pix.pixelformat
                    );
                }
            };

            self.width = actual_pix.width;
            self.height = actual_pix.height;
            self.bytesperline = actual_pix.bytesperline;
            info!(
                "V4L2 Camera initialized on fd {}: {}x{} (bytesperline={}, format: {:?})",
                self.fd, self.width, self.height, self.bytesperline, self.pixel_format
            );

            // 2. Minta 4 buffer MMAP
            let mut req = V4l2Requestbuffers {
                count: 4,
                buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                memory: V4L2_MEMORY_MMAP,
                ..Default::default()
            };

            if let Err(e) = unsafe { xioctl(self.fd, VIDIOC_REQBUFS, &mut req) } {
                bail!("VIDIOC_REQBUFS failed: {}", e);
            }

            if req.count < 2 {
                bail!("Insufficient V4L2 buffer memory (got {} buffers)", req.count);
            }

            // 3. Query dan mmap setiap buffer
            for i in 0..req.count {
                let mut buf = V4l2Buffer {
                    index: i,
                    buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                    memory: V4L2_MEMORY_MMAP,
                    ..Default::default()
                };

                if let Err(e) = unsafe { xioctl(self.fd, VIDIOC_QUERYBUF, &mut buf) } {
                    bail!("VIDIOC_QUERYBUF failed for buffer {}: {}", i, e);
                }

                // SAFETY: Kernel sudah memvalidasi buf.length dan buf.m_offset pada VIDIOC_QUERYBUF.
                let ptr = unsafe {
                    libc::mmap(
                        ptr::null_mut(),
                        buf.length as usize,
                        libc::PROT_READ | libc::PROT_WRITE,
                        libc::MAP_SHARED,
                        self.fd,
                        buf.m_offset as libc::off_t,
                    )
                };

                if ptr == libc::MAP_FAILED {
                    bail!("mmap failed for buffer {}: {}", i, std::io::Error::last_os_error());
                }

                self.buffers.push(MmapBuffer {
                    ptr,
                    len: buf.length as usize,
                });

                // Masukkan buffer ke antrean
                if let Err(e) = unsafe { xioctl(self.fd, VIDIOC_QBUF, &mut buf) } {
                    bail!("VIDIOC_QBUF initial enqueue failed for buffer {}: {}", i, e);
                }
            }

            // 4. Mulai streaming
            let mut stream_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            if let Err(e) = unsafe { xioctl(self.fd, VIDIOC_STREAMON, &mut stream_type) } {
                bail!("VIDIOC_STREAMON failed: {}", e);
            }

            Ok(())
        }

        pub fn capture_frame<F, R>(&mut self, timeout_ms: i32, f: F) -> Result<Option<R>>
        where
            F: FnOnce(&[u8]) -> R,
        {
            let mut pfd = libc::pollfd {
                fd: self.fd,
                events: libc::POLLIN,
                revents: 0,
            };

            // SAFETY: Memanggil poll pada fd terbuka yang valid dengan struct pollfd yang valid.
            let poll_res = unsafe { libc::poll(&mut pfd, 1, timeout_ms) };
            if poll_res < 0 {
                let err = std::io::Error::last_os_error();
                if err.kind() == std::io::ErrorKind::Interrupted {
                    return Ok(None);
                }
                bail!("poll() on camera failed: {}", err);
            }
            if poll_res == 0 {
                // Timeout, belum ada frame yang siap
                return Ok(None);
            }

            let mut buf = V4l2Buffer {
                buf_type: V4L2_BUF_TYPE_VIDEO_CAPTURE,
                memory: V4L2_MEMORY_MMAP,
                ..Default::default()
            };

            if let Err(err) = unsafe { xioctl(self.fd, VIDIOC_DQBUF, &mut buf) } {
                if err.raw_os_error() == Some(libc::EAGAIN) {
                    return Ok(None);
                }
                bail!("VIDIOC_DQBUF failed: {}", err);
            }

            let idx = buf.index as usize;
            let mmap_buf = &self.buffers[idx];
            let bytes_used = buf.bytesused as usize;

            // Periksa apakah frame ditandai error capture hardware
            let result = if (buf.flags & V4L2_BUF_FLAG_ERROR) != 0 {
                warn!("Frame flagged with V4L2_BUF_FLAG_ERROR, skipping corrupted frame");
                None
            } else {
                // SAFETY: mmap_buf.ptr valid dan menunjuk buffer ter-map minimal sebesar mmap_buf.len byte.
                let frame_slice = unsafe {
                    std::slice::from_raw_parts(mmap_buf.ptr as *const u8, bytes_used.min(mmap_buf.len))
                };
                Some(f(frame_slice))
            };

            // Masukkan ulang buffer SETELAH pemanggil selesai memproses/menyalin data frame!
            if let Err(e) = unsafe { xioctl(self.fd, VIDIOC_QBUF, &mut buf) } {
                warn!("VIDIOC_QBUF re-queue failed: {}", e);
            }

            Ok(result)
        }
    }

    impl Drop for Camera {
        fn drop(&mut self) {
            let mut stream_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            let _ = unsafe { xioctl(self.fd, VIDIOC_STREAMOFF, &mut stream_type) };
            // SAFETY: munmap melepas semua buffer yang berhasil di-map, dan libc::close melepas fd kamera.
            unsafe {
                for buf in &self.buffers {
                    libc::munmap(buf.ptr, buf.len);
                }
                libc::close(self.fd);
            }
        }
    }

    /// Menjalankan thread capture kamera dalam mode terpisah:
    /// - Selalu menegosiasikan MJPEG demi frame rate hardware maksimal jika didukung.
    /// - Untuk YUYV: konversi ke RGB sekali, teruskan RGB mentah langsung ke worker AI (0% overhead decode JPEG),
    ///   lalu encode JPEG sesuai permintaan (hanya saat ada penonton stream, dibatasi ~15 FPS untuk memangkas beban CPU 50%).
    ///   Saat idle, hanya meng-encode 1 frame tiap 2 detik untuk preview snapshot.
    #[allow(clippy::too_many_arguments)]
    pub fn spawn_capture_thread_decoupled<F, A, V, O>(
        device_path: String,
        width: u32,
        height: u32,
        fps_cap: u32,
        running: Arc<AtomicBool>,
        has_viewers: V,
        mut on_stream_frame: F,
        mut on_ai_frame: Option<A>,
        mut on_draw_overlay: Option<O>,
    ) -> std::thread::JoinHandle<()>
    where
        F: FnMut(&[u8], PixelFormat, u32, u32) + Send + 'static,
        A: FnMut(&[u8], PixelFormat, u32, u32) + Send + 'static,
        V: Fn() -> bool + Send + 'static,
        O: FnMut(&mut [u8], u32, u32) + Send + 'static,
    {
        std::thread::Builder::new()
            .name("v4l2-capture".to_string())
            .spawn(move || {
                info!("Starting V4L2 capture thread on {} (fps_cap: {})", device_path, fps_cap);
                let base_interval_ms = (1000u64 / (fps_cap.clamp(5, 60) as u64)).clamp(16, 500);
                let stream_interval_ms = base_interval_ms;
                let ai_interval_ms = base_interval_ms;
                let mut last_ai_frame = std::time::Instant::now()
                    .checked_sub(Duration::from_millis(ai_interval_ms))
                    .unwrap_or_else(std::time::Instant::now);
                // SELALU utamakan MJPEG: kompresi hardware kamera berjalan 30 FPS dengan ~0% CPU
                let mut camera = match Camera::open_with_format(&device_path, width, height, false) {
                    Ok(cam) => cam,
                    Err(e) => {
                        error!("Failed to open V4L2 camera {}: {:#}", device_path, e);
                        return;
                    }
                };

                let w = camera.width;
                let h = camera.height;
                let stride = if camera.bytesperline >= w * 2 {
                    camera.bytesperline as usize
                } else {
                    (w * 2) as usize
                };
                let pixel_format = camera.pixel_format;

                println!(
                    "[Camera] Terhubung ke {} (Resolusi: {}x{}, Format: {:?}, Stride: {} bytes, Cap: {} FPS)",
                    device_path, w, h, pixel_format, stride, fps_cap
                );

                let mut rgb_buf = if pixel_format == PixelFormat::Yuyv {
                    vec![0u8; (w * h * 3) as usize]
                } else {
                    Vec::new()
                };
                let mut jpeg_buf = if pixel_format == PixelFormat::Yuyv {
                    Vec::with_capacity((w * h / 4) as usize)
                } else {
                    Vec::new()
                };

                let mut frame_count: u64 = 0;
                let mut last_fps_time = std::time::Instant::now();
                let mut last_stream_encode = std::time::Instant::now();

                while running.load(Ordering::Relaxed) {
                    let capture_res = camera.capture_frame(500, |frame| {
                        let now = std::time::Instant::now();
                        match pixel_format {
                            PixelFormat::Mjpeg => {
                                frame_count = frame_count.saturating_add(1);
                                if frame_count == 30 || (frame_count > 30 && frame_count % 150 == 0) {
                                    if let Ok(dyn_img) = image::load_from_memory_with_format(frame, image::ImageFormat::Jpeg) {
                                        let rgb = dyn_img.to_rgb8();
                                        let (mean_luma, variance) = crate::vision::calculate_luma_and_variance(rgb.as_raw());
                                        if frame_count == 30 {
                                            info!("Baseline kamera '{}' (MJPEG Frame 30): luma={:.1}, variansi={:.1}", device_path, mean_luma, variance);
                                        }
                                        if mean_luma < 10.0 || variance < 4.0 {
                                            warn!(
                                                "Kamera '{}' (MJPEG) tidak menghasilkan gambar valid (luma rata-rata {:.1}, variansi {:.1}) setelah {} frame. \
                                                 Kemungkinan shutter kamera tertutup atau frame statis.",
                                                device_path, mean_luma, variance, frame_count
                                            );
                                        }
                                    }
                                }

                                let viewers_active = has_viewers();
                                let elapsed = now.duration_since(last_stream_encode);
                                let should_stream = if viewers_active {
                                    elapsed >= Duration::from_millis(stream_interval_ms)
                                } else {
                                    elapsed >= Duration::from_millis(2000)
                                };

                                if should_stream {
                                    // 1. Pass-through MJPEG hardware langsung ke stream HTTP (0% decode CPU)
                                    on_stream_frame(frame, PixelFormat::Mjpeg, w, h);
                                    last_stream_encode = now;
                                }

                                // 2. Teruskan byte JPEG mentah ke worker AI HANYA jika memenuhi ai_interval_ms
                                if let Some(ref mut ai_cb) = on_ai_frame {
                                    if now.duration_since(last_ai_frame) >= Duration::from_millis(ai_interval_ms) {
                                        ai_cb(frame, PixelFormat::Mjpeg, w, h);
                                        last_ai_frame = now;
                                    }
                                }
                            }
                            PixelFormat::Yuyv => {
                                frame_count = frame_count.saturating_add(1);
                                if !yuyv_to_rgb_stride(frame, w as usize, h as usize, stride, &mut rgb_buf) {
                                    warn!("Truncated or malformed YUYV frame (len={}), skipping", frame.len());
                                    return;
                                }

                                if frame_count == 30 || (frame_count > 30 && frame_count % 150 == 0) {
                                    let (mean_luma, variance) = crate::vision::calculate_luma_and_variance(&rgb_buf);
                                    if frame_count == 30 {
                                        info!("Baseline kamera '{}' (YUYV Frame 30): luma={:.1}, variansi={:.1}", device_path, mean_luma, variance);
                                    }
                                    if mean_luma < 10.0 || variance < 4.0 {
                                        warn!(
                                            "Kamera '{}' tidak menghasilkan gambar valid (luma rata-rata {:.1}, variansi {:.1}) setelah {} frame. \
                                             Kemungkinan kamera IR, shutter kamera tertutup, atau frame statis. Coba periksa fisik kamera atau ganti index.",
                                            device_path, mean_luma, variance, frame_count
                                        );
                                    }
                                }

                                // 1. Teruskan slice RGB mentah langsung ke worker AI (dibatasi oleh ai_interval_ms)
                                if let Some(ref mut ai_cb) = on_ai_frame {
                                    if now.duration_since(last_ai_frame) >= Duration::from_millis(ai_interval_ms) {
                                        ai_cb(&rgb_buf, PixelFormat::Yuyv, w, h);
                                        last_ai_frame = now;
                                    }
                                }

                                // 2. Encoding JPEG untuk stream (sesuai permintaan & dibatasi oleh fps_cap):
                                let viewers_active = has_viewers();
                                let elapsed_since_last_encode = now.duration_since(last_stream_encode);

                                let should_encode = if viewers_active {
                                    // Dibatasi oleh fps_cap dengan kualitas 60 untuk memangkas beban CPU
                                    elapsed_since_last_encode >= Duration::from_millis(stream_interval_ms)
                                } else {
                                    // Saat TIDAK ada penonton di /stream: encode 1 frame tiap 2.0 detik agar /snapshot.jpg tetap segar
                                    elapsed_since_last_encode >= Duration::from_millis(2000)
                                };

                                if should_encode {
                                    if let Some(ref mut draw_cb) = on_draw_overlay {
                                        draw_cb(&mut rgb_buf, w, h);
                                    }
                                    if let Err(e) = encode_rgb_to_jpeg_into(&rgb_buf, w, h, 60, &mut jpeg_buf) {
                                        error!("JPEG encode failed: {:#}", e);
                                        return;
                                    }
                                    on_stream_frame(&jpeg_buf, PixelFormat::Yuyv, w, h);
                                    last_stream_encode = now;
                                }
                            }
                        }
                    });

                    match capture_res {
                        Ok(Some(())) => {
                            frame_count += 1;
                            let elapsed = last_fps_time.elapsed();
                            if elapsed.as_secs() >= 5 {
                                let fps = frame_count as f64 / elapsed.as_secs_f64();
                                println!("[Camera Live] FPS: {:.1} (Format: {:?})", fps, pixel_format);
                                frame_count = 0;
                                last_fps_time = std::time::Instant::now();
                            }
                        }
                        Ok(None) => {}
                        Err(e) => {
                            error!("Error capturing frame: {:#}", e);
                            std::thread::sleep(Duration::from_millis(50));
                        }
                    }
                }
                info!("V4L2 capture thread stopped.");
            })
            .expect("Failed to spawn camera capture thread")
    }

    /// Menjalankan thread capture OS khusus yang memegang pointer mentah Camera
    /// Menjalankan thread capture kamera yang mengalirkan frame JPEG ke callback on_frame.
    pub fn spawn_capture_thread<F>(
        device_path: String,
        width: u32,
        height: u32,
        running: Arc<AtomicBool>,
        on_frame: F,
    ) -> std::thread::JoinHandle<()>
    where
        F: FnMut(&[u8]) + Send + 'static,
    {
        spawn_capture_thread_with_rgb::<F, fn(&[u8], u32, u32), fn(&mut [u8], u32, u32)>(
            device_path, width, height, running, on_frame, None, None,
        )
    }

    /// Menjalankan thread capture kamera yang mengalirkan JPEG, RGB mentah (untuk AI), dan overlay RGB opsional (untuk UI).
    pub fn spawn_capture_thread_with_rgb<F, R, O>(
        device_path: String,
        width: u32,
        height: u32,
        running: Arc<AtomicBool>,
        mut on_frame: F,
        mut on_rgb: Option<R>,
        mut on_overlay: Option<O>,
    ) -> std::thread::JoinHandle<()>
    where
        F: FnMut(&[u8]) + Send + 'static,
        R: FnMut(&[u8], u32, u32) + Send + 'static,
        O: FnMut(&mut [u8], u32, u32) + Send + 'static,
    {
        std::thread::Builder::new()
            .name("v4l2-capture".to_string())
            .spawn(move || {
                info!("Starting V4L2 capture thread on {}", device_path);
                let prefer_yuyv = on_rgb.is_some() || on_overlay.is_some();
                let mut camera = match Camera::open_with_format(&device_path, width, height, prefer_yuyv) {
                    Ok(cam) => cam,
                    Err(e) => {
                        error!("Failed to open V4L2 camera {}: {:#}", device_path, e);
                        return;
                    }
                };

                let w = camera.width;
                let h = camera.height;
                let stride = if camera.bytesperline >= w * 2 {
                    camera.bytesperline as usize
                } else {
                    (w * 2) as usize
                };
                let pixel_format = camera.pixel_format;

                println!(
                    "[Camera] Terhubung ke {} (Resolusi: {}x{}, Format: {:?}, Stride: {} bytes)",
                    device_path, w, h, pixel_format, stride
                );

                // Buffer yang dialokasikan lebih awal untuk transcoding dan pipeline AI
                let mut rgb_buf = vec![0u8; (w * h * 3) as usize];
                let mut jpeg_buf = Vec::with_capacity((w * h / 4) as usize);

                let mut frame_count: u64 = 0;
                let mut last_fps_time = std::time::Instant::now();

                while running.load(Ordering::Relaxed) {
                    let capture_res = camera.capture_frame(500, |frame| {
                        match pixel_format {
                            PixelFormat::Mjpeg => {
                                if on_rgb.is_some() || on_overlay.is_some() {
                                    if let Ok(dyn_img) = image::load_from_memory_with_format(frame, image::ImageFormat::Jpeg) {
                                        let mut rgb_img = dyn_img.to_rgb8();
                                        let iw = rgb_img.width();
                                        let ih = rgb_img.height();

                                        if let Some(ref mut rgb_cb) = on_rgb {
                                            rgb_cb(rgb_img.as_raw(), iw, ih);
                                        }

                                        let mut has_overlay = false;
                                        if let Some(ref mut overlay_cb) = on_overlay {
                                            overlay_cb(rgb_img.as_mut(), iw, ih);
                                            has_overlay = true;
                                        }

                                        if has_overlay && encode_rgb_to_jpeg_into(rgb_img.as_raw(), iw, ih, 75, &mut jpeg_buf).is_ok() {
                                            on_frame(&jpeg_buf);
                                            return;
                                        }
                                    }
                                }
                                on_frame(frame);
                            }
                            PixelFormat::Yuyv => {
                                if !yuyv_to_rgb_stride(frame, w as usize, h as usize, stride, &mut rgb_buf) {
                                    warn!("Truncated or malformed YUYV frame (len={}), skipping", frame.len());
                                    return;
                                }

                                if let Some(ref mut rgb_cb) = on_rgb {
                                    rgb_cb(&rgb_buf, w, h);
                                }

                                if let Some(ref mut overlay_cb) = on_overlay {
                                    overlay_cb(&mut rgb_buf, w, h);
                                }

                                if let Err(e) = encode_rgb_to_jpeg_into(&rgb_buf, w, h, 75, &mut jpeg_buf) {
                                    error!("JPEG encode failed: {:#}", e);
                                    return;
                                }
                                on_frame(&jpeg_buf);
                            }
                        }
                    });

                    match capture_res {
                        Ok(Some(())) => {
                            frame_count += 1;
                            let elapsed = last_fps_time.elapsed();
                            if elapsed.as_secs() >= 5 {
                                let fps = frame_count as f64 / elapsed.as_secs_f64();
                                println!("[Camera Live] FPS: {:.1} (Format: {:?})", fps, pixel_format);
                                frame_count = 0;
                                last_fps_time = std::time::Instant::now();
                            }
                        }
                        Ok(None) => {
                            // Timeout atau frame rusak yang dilewati
                        }
                        Err(e) => {
                            error!("Error capturing frame: {:#}", e);
                            std::thread::sleep(Duration::from_millis(50));
                        }
                    }
                }
                info!("V4L2 capture thread stopped.");
            })
            .expect("Failed to spawn camera capture thread")
    }
}
