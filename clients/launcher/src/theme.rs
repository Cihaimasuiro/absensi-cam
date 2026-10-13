use std::sync::Arc;
use egui::{Color32, CornerRadius, FontData, FontDefinitions, FontFamily, Stroke, Visuals};

pub const BG: Color32 = Color32::from_rgb(15, 16, 20);
pub const PANEL: Color32 = Color32::from_rgb(20, 22, 28);
pub const CARD_BG: Color32 = Color32::from_rgb(25, 27, 35);
pub const CARD_PENDING_BG: Color32 = Color32::from_rgb(34, 29, 20);
pub const CONTROLS: Color32 = Color32::from_rgb(34, 36, 44);
pub const SUNKEN: Color32 = Color32::from_rgb(10, 11, 14);

// High-contrast text inks for lit rooms & field conditions
pub const INK: Color32 = Color32::from_rgb(245, 245, 242);
pub const INK_MUTED: Color32 = alpha(245, 245, 242, 215);
pub const INK_DIM: Color32 = alpha(245, 245, 242, 175);
pub const INK_FAINT: Color32 = alpha(245, 245, 242, 130);

pub const LINE: Color32 = alpha(255, 255, 255, 42);
pub const LINE_STRONG: Color32 = alpha(245, 245, 242, 120);

pub const ACCENT: Color32 = Color32::from_rgb(59, 130, 246);

pub const ONLINE_FG: Color32 = Color32::from_rgb(52, 211, 153);
pub const ONLINE_BG: Color32 = alpha(16, 45, 35, 220);

pub const OFFLINE_FG: Color32 = Color32::from_rgb(248, 113, 113);
pub const OFFLINE_BG: Color32 = alpha(48, 20, 24, 220);

// Amber strictly reserved for "Needs Decision / Pending"
pub const PENDING_FG: Color32 = Color32::from_rgb(251, 191, 36);
pub const PENDING_BG: Color32 = alpha(48, 38, 16, 220);

// Solid Primary Approve button for pending cards
pub const SOLID_APPROVE_BG: Color32 = Color32::from_rgb(16, 128, 76);

// Reject button styles
pub const REJECT_FG: Color32 = Color32::from_rgb(248, 113, 113);

// Neutral badge for Outdated (does not clash with Amber)
pub const OUTDATED_FG: Color32 = Color32::from_rgb(180, 185, 200);
pub const OUTDATED_BG: Color32 = alpha(255, 255, 255, 22);

// Diagnostic & State Banners
pub const BANNER_WARN_BG: Color32 = alpha(54, 38, 14, 230);
pub const BANNER_ERR_BG: Color32 = alpha(54, 18, 22, 230);

// Active Tab Inverted Chip (High Contrast)
pub const TAB_ACTIVE_BG: Color32 = Color32::from_rgb(240, 242, 246);
pub const TAB_ACTIVE_FG: Color32 = Color32::from_rgb(15, 16, 20);

pub const RADIUS: CornerRadius = CornerRadius::same(3);

const fn alpha(r: u8, g: u8, b: u8, a: u8) -> Color32 {
    Color32::from_rgba_unmultiplied_const(r, g, b, a)
}

pub fn hairline() -> Stroke {
    Stroke::new(1.0, LINE)
}

pub fn temp_color(c: f32) -> Color32 {
    if c < 65.0 {
        ONLINE_FG
    } else if c < 75.0 {
        PENDING_FG
    } else {
        OFFLINE_FG
    }
}

pub fn configure_theme(ctx: &egui::Context) {
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
    visuals.widgets.hovered.bg_fill = Color32::from_rgba_unmultiplied(255, 255, 255, 28);
    visuals.widgets.hovered.bg_stroke = Stroke::new(1.0, LINE_STRONG);
    visuals.widgets.active.bg_fill = Color32::from_rgba_unmultiplied(255, 255, 255, 36);
    visuals.widgets.active.bg_stroke = Stroke::new(1.0, Color32::WHITE);

    ctx.set_visuals(visuals);
}
