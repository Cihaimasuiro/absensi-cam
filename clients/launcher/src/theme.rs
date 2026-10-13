use std::sync::Arc;
use egui::{Color32, CornerRadius, FontData, FontDefinitions, FontFamily, Stroke, Visuals};

pub const BG: Color32 = Color32::from_rgb(18, 19, 22);
pub const PANEL: Color32 = Color32::from_rgb(16, 16, 20);
pub const CONTROLS: Color32 = Color32::from_rgb(30, 31, 34);
pub const SUNKEN: Color32 = Color32::from_rgb(6, 6, 7);

const fn alpha(r: u8, g: u8, b: u8, a: u8) -> Color32 {
    Color32::from_rgba_unmultiplied_const(r, g, b, a)
}

pub const INK: Color32 = Color32::from_rgb(242, 241, 238);
pub const INK_MUTED: Color32 = alpha(242, 241, 238, 153);
pub const INK_DIM: Color32 = alpha(242, 241, 238, 105);

pub const LINE: Color32 = alpha(255, 255, 255, 38);
pub const LINE_STRONG: Color32 = alpha(242, 241, 238, 90);

pub const ACCENT: Color32 = Color32::from_rgb(45, 129, 255);

pub const ONLINE_FG: Color32 = Color32::from_rgb(52, 211, 153);
pub const ONLINE_BG: Color32 = alpha(16, 45, 35, 220);

pub const OFFLINE_FG: Color32 = Color32::from_rgb(248, 113, 113);
pub const OFFLINE_BG: Color32 = alpha(48, 20, 24, 220);

pub const PENDING_FG: Color32 = Color32::from_rgb(251, 191, 36);
pub const PENDING_BG: Color32 = alpha(48, 38, 16, 220);

pub const RADIUS: CornerRadius = CornerRadius::same(3);

pub fn hairline() -> Stroke {
    Stroke::new(1.0, LINE)
}

pub fn configure_theme(ctx: &egui::Context) {
    // 1. Setup Embedded Fonts (Inter, Geist Mono, Archivo)
    let mut fonts = FontDefinitions::default();

    fonts.font_data.insert(
        "inter".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/Inter-Variable.ttf"
        ))),
    );
    fonts.font_data.insert(
        "geist_mono".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/GeistMono-Variable.ttf"
        ))),
    );
    fonts.font_data.insert(
        "archivo".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/Archivo-Variable.ttf"
        ))),
    );

    // Set Inter as default proportional body font
    fonts
        .families
        .entry(FontFamily::Proportional)
        .or_default()
        .insert(0, "inter".to_owned());

    // Set Geist Mono as default monospace HUD font
    fonts
        .families
        .entry(FontFamily::Monospace)
        .or_default()
        .insert(0, "geist_mono".to_owned());

    // Register Archivo as display font
    fonts
        .families
        .insert(FontFamily::Name("display".into()), vec!["archivo".to_owned()]);

    ctx.set_fonts(fonts);

    // 2. Setup Brutalist Visuals
    let mut visuals = Visuals::dark();
    visuals.panel_fill = BG;
    visuals.window_fill = PANEL;
    visuals.extreme_bg_color = SUNKEN;
    visuals.faint_bg_color = CONTROLS;
    visuals.window_stroke = hairline();
    visuals.window_corner_radius = RADIUS;
    visuals.menu_corner_radius = RADIUS;
    visuals.override_text_color = Some(INK);
    visuals.selection.bg_fill = ACCENT;
    visuals.selection.stroke = Stroke::new(1.0, Color32::WHITE);

    // Style button widgets
    for w in [
        &mut visuals.widgets.inactive,
        &mut visuals.widgets.hovered,
        &mut visuals.widgets.active,
        &mut visuals.widgets.open,
    ] {
        w.corner_radius = RADIUS;
    }
    visuals.widgets.inactive.bg_fill = CONTROLS;
    visuals.widgets.inactive.bg_stroke = hairline();
    visuals.widgets.hovered.bg_fill = Color32::from_rgba_unmultiplied(255, 255, 255, 24);
    visuals.widgets.hovered.bg_stroke = Stroke::new(1.0, LINE_STRONG);
    visuals.widgets.active.bg_fill = Color32::from_rgba_unmultiplied(255, 255, 255, 32);
    visuals.widgets.active.bg_stroke = Stroke::new(1.0, Color32::WHITE);

    ctx.set_visuals(visuals);
}
