use std::time::Instant;
use eframe::egui::{self, Color32, Rect, RichText, Sense, Stroke, Vec2};
use launcher_core::DeviceStatus;
use crate::app::LauncherApp;
use crate::theme;
use crate::widgets::draw_status_dot;

pub fn render_header(app: &mut LauncherApp, ui: &mut egui::Ui) {
    let show_subtitle = ui.available_width() >= 840.0;
    ui.horizontal(|ui| {
        ui.label(
            RichText::new("Perangkat")
                .font(theme::font_title())
                .color(theme::TEKS),
        );
        if show_subtitle {
            ui.add_space(8.0);
            ui.label(
                RichText::new("Absensi-Cam · armada edge")
                    .font(theme::font_body())
                    .color(theme::TEKS_REDUP),
            );
        }

        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            // Tombol outline transparan "Pindai jaringan" (tinggi 36, radius 6, stroke #3A403B)
            let scan_text = if app.is_scanning {
                "Memindai..."
            } else {
                "Pindai jaringan"
            };

            let scan_btn = egui::Button::new(
                RichText::new(scan_text)
                    .font(theme::font_body())
                    .color(if app.is_scanning {
                        theme::TEKS_REDUP
                    } else {
                        theme::TEKS
                    }),
            )
            .fill(Color32::TRANSPARENT)
            .stroke(Stroke::new(1.0, theme::OUTLINE_TOMBOL))
            .corner_radius(theme::RADIUS_SM)
            .min_size(Vec2::new(130.0, 36.0));

            let btn_resp = ui.add_enabled(!app.is_scanning, scan_btn);
            if btn_resp.clicked() && !app.is_scanning {
                app.is_scanning = true;
                app.scan_started_at = Some(Instant::now());
                // Langsung hapus notifikasi lama saat pemindaian baru dimulai
                app.notification = None;
            }

            ui.add_space(16.0);

            // Titik status server + teks
            ui.horizontal(|ui| {
                draw_status_dot(
                    ui,
                    if app.server_connected && !app.simulated_server_error {
                        DeviceStatus::Online
                    } else {
                        DeviceStatus::Offline
                    },
                );
                ui.add_space(6.0);
                let server_text = if app.server_connected && !app.simulated_server_error {
                    format!("server: {}", app.server_url)
                } else {
                    "server tidak terjangkau".to_string()
                };
                ui.label(
                    RichText::new(server_text)
                        .font(theme::font_caption())
                        .color(theme::TEKS_REDUP),
                );
            });
        });
    });

    // -----------------------------------------------------------------
    // Keadaan: Banner Tipis (Memindai / Server Putus / Token Habis)
    // -----------------------------------------------------------------
    if app.is_scanning {
        ui.add_space(12.0);
        ui.vertical(|ui| {
            ui.label(
                RichText::new("Memindai subnet LAN 192.168.1.0/24...")
                    .font(theme::font_caption())
                    .color(theme::TEKS_REDUP),
            );
            ui.add_space(4.0);
            let progress = if let Some(started) = app.scan_started_at {
                (started.elapsed().as_secs_f32() / 1.5).min(1.0)
            } else {
                0.0
            };
            let (rect, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 2.0), Sense::hover());
            let painter = ui.painter();
            painter.rect_filled(rect, 0.0, theme::GARIS);
            // Warna netral untuk progress bar (bukan amber)
            let fill_rect = Rect::from_min_size(rect.min, Vec2::new(rect.width() * progress, 2.0));
            painter.rect_filled(fill_rect, 0.0, theme::TEKS_REDUP);
        });
    }

    if app.simulated_server_error {
        ui.add_space(12.0);
        egui::Frame::new()
            .fill(theme::PERMUKAAN)
            .stroke(Stroke::new(1.0, theme::MERAH_OFFLINE))
            .corner_radius(theme::RADIUS_SM)
            .inner_margin(egui::Margin::symmetric(14, 10))
            .show(ui, |ui| {
                ui.horizontal(|ui| {
                    ui.label(
                        RichText::new("Server tidak terjangkau atau token sesi telah habis.")
                            .font(theme::font_body())
                            .color(theme::TEKS),
                    );
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        if ui.button(RichText::new("Ubah URL").font(theme::font_caption())).clicked() {
                            // Ubah URL
                        }
                        if ui.button(RichText::new("Coba lagi").font(theme::font_caption())).clicked() {
                            app.simulated_server_error = false;
                        }
                    });
                });
            });
    }
}
