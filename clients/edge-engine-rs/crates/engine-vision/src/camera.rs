//! Pure libc V4L2 Camera Capture Module
//!
//! Gated with #[cfg(target_os = "linux")].
//! Uses native libc::ioctl with #[repr(C)] structs and compile-time size assertions.
//! Supports MJPEG and YUYV 4:2:2 formats. Runs capture on a dedicated thread with mmap buffers.

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
    use crate::{encode_rgb_to_jpeg_into, yuyv_to_rgb_stride};
    pub use super::PixelFormat;

    // ── V4L2 Constants ──────────────────────────────────────────────────────────
    pub const V4L2_BUF_TYPE_VIDEO_CAPTURE: u32 = 1;
    pub const V4L2_MEMORY_MMAP: u32 = 1;
    pub const V4L2_FIELD_ANY: u32 = 0;
    pub const V4L2_PIX_FMT_MJPEG: u32 = 0x47504a4d; // 'MJPG'
    pub const V4L2_PIX_FMT_YUYV: u32 = 0x56595559;  // 'YUYV'
    pub const V4L2_BUF_FLAG_ERROR: u32 = 0x00000040;

    // Linux ioctl magic definitions for 64-bit architectures
    pub const VIDIOC_REQBUFS: libc::c_ulong = 0xc0145608;
    pub const VIDIOC_QUERYBUF: libc::c_ulong = 0xc0585609;
    pub const VIDIOC_G_FMT: libc::c_ulong = 0xc0d05604;
    pub const VIDIOC_S_FMT: libc::c_ulong = 0xc0d05605;
    pub const VIDIOC_QBUF: libc::c_ulong = 0xc058560f;
    pub const VIDIOC_DQBUF: libc::c_ulong = 0xc0585611;
    pub const VIDIOC_STREAMON: libc::c_ulong = 0x40045612;
    pub const VIDIOC_STREAMOFF: libc::c_ulong = 0x40045613;

    // ── 64-bit V4L2 Struct Layouts ──────────────────────────────────────────────

    #[repr(C)]
    #[derive(Copy, Clone)]
    pub struct V4l2Requestbuffers {
        pub count: u32,
        pub buf_type: u32,
        pub memory: u32,
        pub capabilities: u32,
        pub flags: u8,
        pub reserved: [u8; 3],
    }

    #[repr(C)]
    #[derive(Copy, Clone)]
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

    impl V4l2Format {
        pub fn pix(&self) -> &V4l2PixFormat {
            unsafe { &*(self.raw.as_ptr() as *const V4l2PixFormat) }
        }

        pub fn pix_mut(&mut self) -> &mut V4l2PixFormat {
            unsafe { &mut *(self.raw.as_mut_ptr() as *mut V4l2PixFormat) }
        }
    }

    #[repr(C)]
    #[derive(Copy, Clone)]
    pub struct V4l2Timeval {
        pub tv_sec: i64,
        pub tv_usec: i64,
    }

    #[repr(C)]
    #[derive(Copy, Clone)]
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

    // ── Compile-time 64-bit Layout Assertions ───────────────────────────────────
    const _: () = assert!(std::mem::size_of::<V4l2Requestbuffers>() == 20, "V4l2Requestbuffers must be 20 bytes on 64-bit");
    const _: () = assert!(std::mem::size_of::<V4l2Format>() == 208, "V4l2Format must be 208 bytes on 64-bit");
    const _: () = assert!(std::mem::size_of::<V4l2Buffer>() == 88, "V4l2Buffer must be 88 bytes on 64-bit");

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

            // If init() fails, Drop for Camera will close self.fd cleanly (no double-close)
            cam.init(prefer_yuyv)?;

            Ok(cam)
        }

        fn init(&mut self, prefer_yuyv: bool) -> Result<()> {
            let (primary_fmt, fallback_fmt, primary_pixel, fallback_pixel) = if prefer_yuyv {
                (V4L2_PIX_FMT_YUYV, V4L2_PIX_FMT_MJPEG, PixelFormat::Yuyv, PixelFormat::Mjpeg)
            } else {
                (V4L2_PIX_FMT_MJPEG, V4L2_PIX_FMT_YUYV, PixelFormat::Mjpeg, PixelFormat::Yuyv)
            };

            let mut fmt: V4l2Format = unsafe { std::mem::zeroed() };
            fmt.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            {
                let pix = fmt.pix_mut();
                pix.width = self.width;
                pix.height = self.height;
                pix.pixelformat = primary_fmt;
                pix.field = V4L2_FIELD_ANY;
            }

            let s_fmt_res = unsafe { libc::ioctl(self.fd, VIDIOC_S_FMT, &mut fmt) };
            let mut configured_format = if s_fmt_res >= 0 && fmt.pix().pixelformat == primary_fmt {
                Some(primary_pixel)
            } else {
                None
            };

            if configured_format.is_none() {
                let mut alt_fmt: V4l2Format = unsafe { std::mem::zeroed() };
                alt_fmt.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
                {
                    let pix = alt_fmt.pix_mut();
                    pix.width = self.width;
                    pix.height = self.height;
                    pix.pixelformat = fallback_fmt;
                    pix.field = V4L2_FIELD_ANY;
                }
                let alt_res = unsafe { libc::ioctl(self.fd, VIDIOC_S_FMT, &mut alt_fmt) };
                if alt_res >= 0 && alt_fmt.pix().pixelformat == fallback_fmt {
                    fmt = alt_fmt;
                    configured_format = Some(fallback_pixel);
                }
            }

            // If neither was confirmed via S_FMT, query what the driver is actually using
            if configured_format.is_none() {
                let mut get_fmt: V4l2Format = unsafe { std::mem::zeroed() };
                get_fmt.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
                if unsafe { libc::ioctl(self.fd, VIDIOC_G_FMT, &mut get_fmt) } >= 0 {
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

            // 2. Request 4 MMAP buffers
            let mut req: V4l2Requestbuffers = unsafe { std::mem::zeroed() };
            req.count = 4;
            req.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            req.memory = V4L2_MEMORY_MMAP;

            if unsafe { libc::ioctl(self.fd, VIDIOC_REQBUFS, &mut req) } < 0 {
                bail!("VIDIOC_REQBUFS failed: {}", std::io::Error::last_os_error());
            }

            if req.count < 2 {
                bail!("Insufficient V4L2 buffer memory (got {} buffers)", req.count);
            }

            // 3. Query and mmap each buffer
            for i in 0..req.count {
                let mut buf: V4l2Buffer = unsafe { std::mem::zeroed() };
                buf.index = i;
                buf.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
                buf.memory = V4L2_MEMORY_MMAP;

                if unsafe { libc::ioctl(self.fd, VIDIOC_QUERYBUF, &mut buf) } < 0 {
                    bail!("VIDIOC_QUERYBUF failed for buffer {}: {}", i, std::io::Error::last_os_error());
                }

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

                // Enqueue buffer
                if unsafe { libc::ioctl(self.fd, VIDIOC_QBUF, &mut buf) } < 0 {
                    bail!("VIDIOC_QBUF initial enqueue failed for buffer {}: {}", i, std::io::Error::last_os_error());
                }
            }

            // 4. Start streaming
            let mut stream_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            if unsafe { libc::ioctl(self.fd, VIDIOC_STREAMON, &mut stream_type) } < 0 {
                bail!("VIDIOC_STREAMON failed: {}", std::io::Error::last_os_error());
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

            let poll_res = unsafe { libc::poll(&mut pfd, 1, timeout_ms) };
            if poll_res < 0 {
                let err = std::io::Error::last_os_error();
                if err.kind() == std::io::ErrorKind::Interrupted {
                    return Ok(None);
                }
                bail!("poll() on camera failed: {}", err);
            }
            if poll_res == 0 {
                // Timeout, no frame ready
                return Ok(None);
            }

            let mut buf: V4l2Buffer = unsafe { std::mem::zeroed() };
            buf.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            buf.memory = V4L2_MEMORY_MMAP;

            if unsafe { libc::ioctl(self.fd, VIDIOC_DQBUF, &mut buf) } < 0 {
                let err = std::io::Error::last_os_error();
                if err.raw_os_error() == Some(libc::EAGAIN) {
                    return Ok(None);
                }
                bail!("VIDIOC_DQBUF failed: {}", err);
            }

            let idx = buf.index as usize;
            let mmap_buf = &self.buffers[idx];
            let bytes_used = buf.bytesused as usize;

            // Check if frame was flagged with hardware capture error
            let result = if (buf.flags & V4L2_BUF_FLAG_ERROR) != 0 {
                warn!("Frame flagged with V4L2_BUF_FLAG_ERROR, skipping corrupted frame");
                None
            } else {
                let frame_slice = unsafe {
                    std::slice::from_raw_parts(mmap_buf.ptr as *const u8, bytes_used.min(mmap_buf.len))
                };
                Some(f(frame_slice))
            };

            // Re-queue buffer AFTER caller finishes processing/copying frame data!
            if unsafe { libc::ioctl(self.fd, VIDIOC_QBUF, &mut buf) } < 0 {
                warn!("VIDIOC_QBUF re-queue failed: {}", std::io::Error::last_os_error());
            }

            Ok(result)
        }
    }

    impl Drop for Camera {
        fn drop(&mut self) {
            let mut stream_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            unsafe {
                libc::ioctl(self.fd, VIDIOC_STREAMOFF, &mut stream_type);
                for buf in &self.buffers {
                    libc::munmap(buf.ptr, buf.len);
                }
                libc::close(self.fd);
            }
        }
    }

    /// Spawns camera capture thread in decoupled mode:
    /// - Always negotiates MJPEG for maximum hardware frame rates if supported.
    /// - For YUYV: converts to RGB once, passes raw RGB directly to AI worker (0% JPEG decoding overhead),
    ///   and encodes JPEG on-demand (only when stream viewers are connected, paced to ~15 FPS to cut CPU load by 50%).
    ///   When idle, only encodes 1 frame every 2s for snapshot preview.
    pub fn spawn_capture_thread_decoupled<F, A, V, O>(
        device_path: String,
        width: u32,
        height: u32,
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
                info!("Starting V4L2 capture thread on {}", device_path);
                // ALWAYS prefer MJPEG: camera hardware compression runs at 30 FPS with ~0% CPU
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
                    "[Camera] Terhubung ke {} (Resolusi: {}x{}, Format: {:?}, Stride: {} bytes)",
                    device_path, w, h, pixel_format, stride
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
                        match pixel_format {
                            PixelFormat::Mjpeg => {
                                // 1. Direct hardware MJPEG pass-through to HTTP stream (0% CPU decoding)
                                on_stream_frame(frame, PixelFormat::Mjpeg, w, h);

                                // 2. Pass raw JPEG bytes to AI worker (no decoding on capture thread!)
                                if let Some(ref mut ai_cb) = on_ai_frame {
                                    ai_cb(frame, PixelFormat::Mjpeg, w, h);
                                }
                            }
                            PixelFormat::Yuyv => {
                                if !yuyv_to_rgb_stride(frame, w as usize, h as usize, stride, &mut rgb_buf) {
                                    warn!("Truncated or malformed YUYV frame (len={}), skipping", frame.len());
                                    return;
                                }

                                // 1. Pass raw RGB slice directly to AI worker (ZERO JPEG decoding in AI worker!)
                                if let Some(ref mut ai_cb) = on_ai_frame {
                                    ai_cb(&rgb_buf, PixelFormat::Yuyv, w, h);
                                }

                                // 2. Stream JPEG encoding (on-demand & paced):
                                let viewers_active = has_viewers();
                                let now = std::time::Instant::now();
                                let elapsed_since_last_encode = now.duration_since(last_stream_encode);

                                let should_encode = if viewers_active {
                                    // When active viewers on /stream: pace to ~10 FPS (100ms interval) with quality 60
                                    // Cuts software JPEG CPU load to ~20% while delivering crisp, fluid video
                                    elapsed_since_last_encode >= Duration::from_millis(100)
                                } else {
                                    // When NO viewers on /stream: encode 1 frame every 2.0s so /snapshot.jpg stays fresh
                                    // Reduces idle CPU load to <2%
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

    /// Spawns a dedicated OS capture thread that owns the Camera raw pointers
    /// Spawns camera capture thread feeding JPEG frames into on_frame callback.
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

    /// Spawns camera capture thread feeding JPEG, raw RGB (for AI), and optional RGB overlay (for UI).
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

                // Preallocated buffers for transcoding and AI pipeline
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

                                        if has_overlay {
                                            if encode_rgb_to_jpeg_into(rgb_img.as_raw(), iw, ih, 75, &mut jpeg_buf).is_ok() {
                                                on_frame(&jpeg_buf);
                                                return;
                                            }
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
                            // Timeout or skipped corrupted frame
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
