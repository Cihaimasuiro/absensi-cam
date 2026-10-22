use anyhow::{bail, Context, Result};
use std::fs::{File, OpenOptions};
use std::io::Write;
use std::path::PathBuf;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ColorFormat {
    Rgb565,
    Rgb888,
    Bgr888,
    Bgra8888,
}

/// Native Linux Framebuffer display driver (/dev/fb0, /dev/fb1, dll.)
/// Mendukung layar TFT SPI ST7789, ILI9341, atau HDMI tanpa X11/Wayland.
pub struct FramebufferDisplay {
    device_path: PathBuf,
    width: u32,
    height: u32,
    bpp: u32,
    format: ColorFormat,
    file: Option<File>,
}

impl FramebufferDisplay {
    pub fn open(path: impl Into<PathBuf>, default_w: u32, default_h: u32) -> Result<Self> {
        let device_path = path.into();
        if !device_path.exists() {
            bail!("Framebuffer device {:?} tidak ditemukan", device_path);
        }

        let file = OpenOptions::new()
            .read(true)
            .write(true)
            .open(&device_path)
            .with_context(|| format!("Gagal membuka {:?}", device_path))?;

        #[allow(unused_mut)]
        let mut width = default_w;
        #[allow(unused_mut)]
        let mut height = default_h;
        #[allow(unused_mut)]
        let mut bpp = 16;
        #[allow(unused_mut)]
        let mut format = ColorFormat::Rgb565;

        #[cfg(target_os = "linux")]
        {
            use std::os::unix::io::AsRawFd;
            // Nilai magic ioctl FBIOGET_VSCREENINFO
            const FBIOGET_VSCREENINFO: libc::c_ulong = 0x4600;

            #[repr(C)]
            #[derive(Default)]
            struct FbVarScreeninfo {
                xres: u32,
                yres: u32,
                xres_virtual: u32,
                yres_virtual: u32,
                xoffset: u32,
                yoffset: u32,
                bits_per_pixel: u32,
                grayscale: u32,
                red: [u32; 3],
                green: [u32; 3],
                blue: [u32; 3],
                transp: [u32; 3],
                nonstd: u32,
                activate: u32,
                height: u32,
                width: u32,
                accel_flags: u32,
                pixclock: u32,
                left_margin: u32,
                right_margin: u32,
                upper_margin: u32,
                lower_margin: u32,
                hsync_len: u32,
                vsync_len: u32,
                sync: u32,
                vmode: u32,
                rotate: u32,
                colorspace: u32,
                reserved: [u32; 4],
            }

            let mut vinfo = FbVarScreeninfo::default();
            let fd = file.as_raw_fd();
            let ret = unsafe { libc::ioctl(fd, FBIOGET_VSCREENINFO, &mut vinfo) };
            if ret == 0 && vinfo.xres > 0 && vinfo.yres > 0 {
                width = vinfo.xres;
                height = vinfo.yres;
                bpp = vinfo.bits_per_pixel;
                format = match bpp {
                    16 => ColorFormat::Rgb565,
                    24 => ColorFormat::Rgb888,
                    32 => ColorFormat::Bgra8888,
                    _ => ColorFormat::Rgb565,
                };
                tracing::info!("Framebuffer {:?} aktif: {}x{} @ {} bpp ({:?})", device_path, width, height, bpp, format);
            }
        }

        Ok(Self {
            device_path,
            width,
            height,
            bpp,
            format,
            file: Some(file),
        })
    }

    pub fn device_path(&self) -> &PathBuf {
        &self.device_path
    }

    pub fn width(&self) -> u32 {
        self.width
    }

    pub fn height(&self) -> u32 {
        self.height
    }

    pub fn bpp(&self) -> u32 {
        self.bpp
    }

    pub fn format(&self) -> ColorFormat {
        self.format
    }

    /// Tampilkan buffer RGB frame ke layar fisik
    pub fn render_frame(&mut self, src_rgb: &[u8], src_w: u32, src_h: u32) -> Result<()> {
        let file = match self.file.as_mut() {
            Some(f) => f,
            None => return Ok(()),
        };

        // Resize sederhana nearest-neighbor dari resolusi kamera ke layar TFT
        let dst_w = self.width;
        let dst_h = self.height;

        match self.format {
            ColorFormat::Rgb565 => {
                let mut out_buf = Vec::with_capacity((dst_w * dst_h * 2) as usize);
                for dy in 0..dst_h {
                    let sy = (dy * src_h) / dst_h;
                    for dx in 0..dst_w {
                        let sx = (dx * src_w) / dst_w;
                        let src_idx = ((sy * src_w + sx) * 3) as usize;
                        if src_idx + 2 < src_rgb.len() {
                            let r = src_rgb[src_idx] >> 3;
                            let g = src_rgb[src_idx + 1] >> 2;
                            let b = src_rgb[src_idx + 2] >> 3;
                            let rgb565 = ((r as u16) << 11) | ((g as u16) << 5) | (b as u16);
                            out_buf.extend_from_slice(&rgb565.to_le_bytes());
                        } else {
                            out_buf.extend_from_slice(&[0, 0]);
                        }
                    }
                }
                use std::io::Seek;
                file.seek(std::io::SeekFrom::Start(0))?;
                file.write_all(&out_buf)?;
            }
            ColorFormat::Rgb888 | ColorFormat::Bgr888 | ColorFormat::Bgra8888 => {
                let bytes_per_pixel = (self.bpp / 8) as usize;
                let mut out_buf = Vec::with_capacity((dst_w * dst_h * bytes_per_pixel as u32) as usize);
                for dy in 0..dst_h {
                    let sy = (dy * src_h) / dst_h;
                    for dx in 0..dst_w {
                        let sx = (dx * src_w) / dst_w;
                        let src_idx = ((sy * src_w + sx) * 3) as usize;
                        if src_idx + 2 < src_rgb.len() {
                            let r = src_rgb[src_idx];
                            let g = src_rgb[src_idx + 1];
                            let b = src_rgb[src_idx + 2];
                            match self.format {
                                ColorFormat::Bgr888 => out_buf.extend_from_slice(&[b, g, r]),
                                ColorFormat::Bgra8888 => out_buf.extend_from_slice(&[b, g, r, 255]),
                                _ => out_buf.extend_from_slice(&[r, g, b]),
                            }
                        }
                    }
                }
                use std::io::Seek;
                file.seek(std::io::SeekFrom::Start(0))?;
                file.write_all(&out_buf)?;
            }
        }

        Ok(())
    }
}
