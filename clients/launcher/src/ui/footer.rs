use std::time::Duration;
use eframe::egui::{self, RichText, Sense, Vec2};
use launcher_core::DeviceStatus;
use crate::app::LauncherApp;
use crate::theme;

pub fn render_footer(app: &mut LauncherApp, ui: &mut egui::Ui) {
    ui.with_layout(egui::Layout::bottom_up(egui::Align::LEFT), |ui| {
        ui.add_space(4.0);
        let (f_sep, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 1.0), Sense::hover());
        ui.painter().rect_filled(f_sep, 0.0, theme::PEMISAH_BARIS);
        ui.add_space(8.0);

        ui.horizontal(|ui| {
            let registered_count = app
                .devices
                .iter()
                .filter(|d| d.status != DeviceStatus::PendingPairing)
                .count();
            let pending_count = app
                .devices
                .iter()
                .filter(|d| d.status == DeviceStatus::PendingPairing)
                .count();

            let footer_scan_text = if pending_count > 0 {
                format!(
                    "Dipindai {} · {} perangkat terdaftar · {} menunggu",
                    app.last_scanned_time, registered_count, pending_count
                )
            } else {
                format!(
                    "Dipindai {} · {} perangkat terdaftar",
                    app.last_scanned_time, registered_count
                )
            };

            ui.label(
                RichText::new(footer_scan_text)
                    .font(theme::font_caption())
                    .color(theme::TEKS_REDUP),
            );

            // Notifikasi toast (warna netral/terang, BUKAN amber)
            if let Some((ref note, timestamp)) = app.notification {
                if timestamp.elapsed() > Duration::from_secs(4) {
                    app.notification = None;
                } else {
                    let remaining = Duration::from_secs(4).saturating_sub(timestamp.elapsed());
                    ui.ctx().request_repaint_after(remaining);
                    ui.add_space(12.0);
                    ui.label(
                        RichText::new(format!("· {note}"))
                            .font(theme::font_caption())
                            .color(theme::TEKS),
                    );
                }
            }

            // Kalimat kanan
            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.label(
                    RichText::new("Tampilan contoh dengan data simulasi")
                        .font(theme::font_caption())
                        .color(theme::TEKS_REDUP),
                );

                // Feature flag dev-states: Toolbar simulasi
                #[cfg(feature = "dev-states")]
                {
                    ui.add_space(16.0);
                    if ui.small_button("Simulasi: Putus").clicked() {
                        app.simulated_server_error = !app.simulated_server_error;
                    }
                }
            });
        });
    });
}
