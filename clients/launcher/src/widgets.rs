use eframe::egui::{self, Color32, Pos2, Rect, Response, Sense, Stroke, StrokeKind, Vec2};
use launcher_core::DeviceStatus;
use crate::theme;

/// Helper untuk mengalokasikan scoped UI pada koordinat tepat (sub-sel tabel)
/// dengan perataan vertikal tengah (Align::Center) di egui 0.36
pub fn child_cell<R>(
    ui: &mut egui::Ui,
    rect: Rect,
    add_contents: impl FnOnce(&mut egui::Ui) -> R,
) -> R {
    ui.scope_builder(
        egui::UiBuilder::new()
            .max_rect(rect)
            .layout(egui::Layout::left_to_right(egui::Align::Center)),
        add_contents,
    )
    .inner
}

/// Gambar titik status 8px:
/// - Online: lingkaran penuh hijau
/// - Offline: cincin 2px merah
/// - Pending: lingkaran penuh amber
pub fn draw_status_dot(ui: &mut egui::Ui, status: DeviceStatus) {
    let (rect, _) = ui.allocate_exact_size(Vec2::new(10.0, 10.0), Sense::hover());
    let center = rect.center();
    let painter = ui.painter();
    match status {
        DeviceStatus::Online => {
            painter.circle_filled(center, 4.0, theme::HIJAU_ONLINE);
        }
        DeviceStatus::Offline => {
            painter.circle_stroke(center, 4.0, Stroke::new(2.0, theme::MERAH_OFFLINE));
        }
        DeviceStatus::PendingPairing => {
            painter.circle_filled(center, 4.0, theme::AMBER);
        }
    }
}

/// Tombol tiga titik digambar dengan painter (circle_filled), TANPA glif teks
pub fn draw_three_dots_button(ui: &mut egui::Ui) -> Response {
    let size = Vec2::new(36.0, 36.0);
    let (rect, response) = ui.allocate_exact_size(size, Sense::click());
    if ui.is_rect_visible(rect) {
        let painter = ui.painter();
        let is_hovered = response.hovered();
        let is_active = response.is_pointer_button_down_on();

        let border_stroke = if is_active {
            Stroke::new(1.0, theme::TEKS)
        } else if is_hovered {
            Stroke::new(1.0, theme::TEKS_REDUP)
        } else {
            Stroke::new(1.0, theme::OUTLINE_TOMBOL)
        };

        let bg_color = if is_hovered || is_active {
            theme::PERMUKAAN
        } else {
            Color32::TRANSPARENT
        };

        painter.rect(rect, theme::RADIUS_SM, bg_color, border_stroke, StrokeKind::Inside);

        let dot_color = if is_hovered || is_active {
            theme::TEKS
        } else {
            theme::TEKS_REDUP
        };

        let center = rect.center();
        painter.circle_filled(Pos2::new(center.x, center.y - 5.0), 1.5, dot_color);
        painter.circle_filled(center, 1.5, dot_color);
        painter.circle_filled(Pos2::new(center.x, center.y + 5.0), 1.5, dot_color);
    }
    response
}

/// Tab sebagai teks dengan garis bawah 2px untuk yang aktif, jumlah berwarna redup
pub fn draw_tab_item(ui: &mut egui::Ui, label: &str, count: usize, is_active: bool) -> bool {
    let text_font = theme::font_body();
    let count_font = theme::font_caption();

    let text_color = if is_active {
        theme::TEKS
    } else {
        theme::TEKS_REDUP
    };
    let count_color = theme::TEKS_REDUP;

    let galley_label = ui.painter().layout_no_wrap(label.to_string(), text_font, text_color);
    let galley_count = ui.painter().layout_no_wrap(format!(" {count}"), count_font, count_color);

    let total_width = galley_label.size().x + galley_count.size().x;
    let height = 30.0;

    let (rect, response) = ui.allocate_exact_size(Vec2::new(total_width, height), Sense::click());

    if ui.is_rect_visible(rect) {
        let painter = ui.painter();
        let y_pos = rect.top() + (height - galley_label.size().y) / 2.0 - 2.0;
        let label_pos = Pos2::new(rect.left(), y_pos);
        painter.galley(label_pos, galley_label, text_color);

        let count_pos = Pos2::new(label_pos.x + rect.width() - galley_count.size().x, y_pos + 1.0);
        painter.galley(count_pos, galley_count, count_color);

        // Garis bawah 2px untuk yang aktif
        if is_active {
            painter.line_segment(
                [
                    Pos2::new(rect.left(), rect.bottom() - 1.0),
                    Pos2::new(rect.right(), rect.bottom() - 1.0),
                ],
                Stroke::new(2.0, theme::TEKS),
            );
        }
    }

    response.clicked()
}
