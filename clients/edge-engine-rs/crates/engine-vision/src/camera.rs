//! Pure libc V4L2 Camera Capture Module
//!
//! Gated with #[cfg(target_os = "linux")].
//! Uses native libc::ioctl with #[repr(C)] structs and compile-time size assertions.
//! Enforces MJPEG only. Runs capture on a dedicated thread with mmap buffers.

#[cfg(target_os = "linux")]
pub mod linux {
    use anyhow::{bail, Context, Result};
    use std::ffi::CString;
    use std::os::unix::io::RawFd;
    use std::ptr;
    use std::sync::atomic::{AtomicBool, Ordering};
    use std::sync::Arc;
    use std::time::Duration;
    use tracing::{error, info, warn};

    // ── V4L2 Constants ──────────────────────────────────────────────────────────
    pub const V4L2_BUF_TYPE_VIDEO_CAPTURE: u32 = 1;
    pub const V4L2_MEMORY_MMAP: u32 = 1;
    pub const V4L2_FIELD_ANY: u32 = 0;
    pub const V4L2_PIX_FMT_MJPEG: u32 = 0x47504a4d; // 'MJPG'

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
        width: u32,
        height: u32,
    }

    impl Camera {
        pub fn open(device_path: &str, width: u32, height: u32) -> Result<Self> {
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
            };

            if let Err(e) = cam.init() {
                unsafe { libc::close(fd) };
                return Err(e);
            }

            Ok(cam)
        }

        fn init(&mut self) -> Result<()> {
            // 1. Set format to MJPEG
            let mut fmt: V4l2Format = unsafe { std::mem::zeroed() };
            fmt.buf_type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
            {
                let pix = fmt.pix_mut();
                pix.width = self.width;
                pix.height = self.height;
                pix.pixelformat = V4L2_PIX_FMT_MJPEG;
                pix.field = V4L2_FIELD_ANY;
            }

            if unsafe { libc::ioctl(self.fd, VIDIOC_S_FMT, &mut fmt) } < 0 {
                bail!("VIDIOC_S_FMT failed: {}", std::io::Error::last_os_error());
            }

            let actual_pix = fmt.pix();
            if actual_pix.pixelformat != V4L2_PIX_FMT_MJPEG {
                let fourcc = actual_pix.pixelformat.to_le_bytes();
                bail!(
                    "Driver does not support MJPEG. Requested 'MJPG', got fourcc: '{}{}{}{}' (0x{:08x})",
                    fourcc[0] as char, fourcc[1] as char, fourcc[2] as char, fourcc[3] as char, actual_pix.pixelformat
                );
            }

            self.width = actual_pix.width;
            self.height = actual_pix.height;
            info!("V4L2 Camera initialized: {}x{} MJPEG", self.width, self.height);

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

        pub fn capture_frame(&mut self, timeout_ms: i32) -> Result<Option<&[u8]>> {
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

            let frame_slice = unsafe {
                std::slice::from_raw_parts(mmap_buf.ptr as *const u8, bytes_used.min(mmap_buf.len))
            };

            // Immediately re-queue buffer so the camera can continue filling it
            if unsafe { libc::ioctl(self.fd, VIDIOC_QBUF, &mut buf) } < 0 {
                warn!("VIDIOC_QBUF re-queue failed: {}", std::io::Error::last_os_error());
            }

            Ok(Some(frame_slice))
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

    /// Spawns a dedicated OS capture thread that owns the Camera raw pointers
    /// and feeds frames into a callback or channel.
    pub fn spawn_capture_thread<F>(
        device_path: String,
        width: u32,
        height: u32,
        running: Arc<AtomicBool>,
        mut on_frame: F,
    ) -> std::thread::JoinHandle<()>
    where
        F: FnMut(&[u8]) + Send + 'static,
    {
        std::thread::Builder::new()
            .name("v4l2-capture".to_string())
            .spawn(move || {
                info!("Starting V4L2 capture thread on {}", device_path);
                let mut camera = match Camera::open(&device_path, width, height) {
                    Ok(cam) => cam,
                    Err(e) => {
                        error!("Failed to open V4L2 camera {}: {:#}", device_path, e);
                        return;
                    }
                };

                while running.load(Ordering::Relaxed) {
                    match camera.capture_frame(500) {
                        Ok(Some(frame)) => {
                            on_frame(frame);
                        }
                        Ok(None) => {
                            // Timeout without frame
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
