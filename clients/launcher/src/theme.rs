use std::sync::Arc;
use egui::{Color32, CornerRadius, FontData, FontDefinitions, FontFamily, FontId, Stroke, Visuals};

// WARNA (hex) dari spesifikasi kanvas:
// latar #121413 · permukaan #181B19 · garis #262A27 · pemisah baris #1F2321
// teks #E7EAE5 · teks redup #98A199 · outline tombol #3A403B
// amber #E8B449 (teks di atas amber #1A1405) · latar blok menunggu #1B1912
// hijau online #62C48F · merah offline #E5836F

pub const LATAR: Color32 = Color32::from_rgb(0x12, 0x14, 0x13);
pub const PERMUKAAN: Color32 = Color32::from_rgb(0x18, 0x1B, 0x19);
pub const GARIS: Color32 = Color32::from_rgb(0x26, 0x2A, 0x27);
pub const PEMISAH_BARIS: Color32 = Color32::from_rgb(0x1F, 0x23, 0x21);

pub const TEKS: Color32 = Color32::from_rgb(0xE7, 0xEA, 0xE5);
pub const TEKS_REDUP: Color32 = Color32::from_rgb(0x98, 0xA1, 0x99);
pub const OUTLINE_TOMBOL: Color32 = Color32::from_rgb(0x3A, 0x40, 0x3B);

pub const AMBER: Color32 = Color32::from_rgb(0xE8, 0xB4, 0x49);
pub const TEKS_DI_ATAS_AMBER: Color32 = Color32::from_rgb(0x1A, 0x14, 0x05);
pub const LATAR_MENUNGGU: Color32 = Color32::from_rgb(0x1B, 0x19, 0x12);

pub const HIJAU_ONLINE: Color32 = Color32::from_rgb(0x62, 0xC4, 0x8F);
pub const MERAH_OFFLINE: Color32 = Color32::from_rgb(0xE5, 0x83, 0x6F);

pub const RADIUS_SM: CornerRadius = CornerRadius::same(6);

pub fn font_title() -> FontId {
    FontId::new(22.0, FontFamily::Name("sans_semibold".into()))
}

pub fn font_subheading() -> FontId {
    FontId::new(18.0, FontFamily::Name("sans_semibold".into()))
}

pub fn font_mac_large() -> FontId {
    FontId::new(20.0, FontFamily::Monospace)
}

pub fn font_body() -> FontId {
    FontId::new(14.0, FontFamily::Proportional)
}

pub fn font_body_medium() -> FontId {
    FontId::new(14.0, FontFamily::Name("sans_medium".into()))
}

pub fn font_technical() -> FontId {
    FontId::new(13.0, FontFamily::Monospace)
}

pub fn font_caption() -> FontId {
    FontId::new(12.0, FontFamily::Proportional)
}

pub fn font_table_header() -> FontId {
    FontId::new(12.0, FontFamily::Proportional)
}

pub fn font_technical_sm() -> FontId {
    FontId::new(12.0, FontFamily::Monospace)
}

pub fn configure_theme(ctx: &egui::Context) {
    let mut fonts = FontDefinitions::default();

    fonts.font_data.insert(
        "ibm_plex_sans_regular".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/IBMPlexSans-Regular.ttf"
        ))),
    );
    fonts.font_data.insert(
        "ibm_plex_sans_medium".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/IBMPlexSans-Medium.ttf"
        ))),
    );
    fonts.font_data.insert(
        "ibm_plex_sans_semibold".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/IBMPlexSans-SemiBold.ttf"
        ))),
    );
    fonts.font_data.insert(
        "ibm_plex_mono_regular".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/IBMPlexMono-Regular.ttf"
        ))),
    );
    fonts.font_data.insert(
        "ibm_plex_mono_medium".to_owned(),
        Arc::new(FontData::from_static(include_bytes!(
            "../assets/fonts/IBMPlexMono-Medium.ttf"
        ))),
    );

    // Default Proportional -> IBM Plex Sans 400
    fonts
        .families
        .entry(FontFamily::Proportional)
        .or_default()
        .insert(0, "ibm_plex_sans_regular".to_owned());

    // Default Monospace -> IBM Plex Mono 400
    fonts
        .families
        .entry(FontFamily::Monospace)
        .or_default()
        .insert(0, "ibm_plex_mono_regular".to_owned());

    // Named families for Medium and SemiBold
    fonts.families.insert(
        FontFamily::Name("sans_medium".into()),
        vec![
            "ibm_plex_sans_medium".to_owned(),
            "ibm_plex_sans_regular".to_owned(),
        ],
    );
    fonts.families.insert(
        FontFamily::Name("sans_semibold".into()),
        vec![
            "ibm_plex_sans_semibold".to_owned(),
            "ibm_plex_sans_medium".to_owned(),
            "ibm_plex_sans_regular".to_owned(),
        ],
    );
    fonts.families.insert(
        FontFamily::Name("mono_medium".into()),
        vec![
            "ibm_plex_mono_medium".to_owned(),
            "ibm_plex_mono_regular".to_owned(),
        ],
    );

    ctx.set_fonts(fonts);

    let mut visuals = Visuals::dark();
    visuals.panel_fill = LATAR;
    visuals.window_fill = PERMUKAAN;
    visuals.extreme_bg_color = LATAR;
    visuals.faint_bg_color = PERMUKAAN;
    visuals.window_stroke = Stroke::new(1.0, GARIS);
    visuals.window_corner_radius = RADIUS_SM;
    visuals.menu_corner_radius = RADIUS_SM;
    visuals.override_text_color = Some(TEKS);
    visuals.selection.bg_fill = LATAR_MENUNGGU;
    visuals.selection.stroke = Stroke::new(1.0, AMBER);

    for w in [
        &mut visuals.widgets.inactive,
        &mut visuals.widgets.hovered,
        &mut visuals.widgets.active,
        &mut visuals.widgets.open,
    ] {
        w.corner_radius = RADIUS_SM;
    }

    visuals.widgets.inactive.bg_fill = Color32::TRANSPARENT;
    visuals.widgets.inactive.bg_stroke = Stroke::new(1.0, OUTLINE_TOMBOL);
    visuals.widgets.inactive.fg_stroke = Stroke::new(1.0, TEKS);

    visuals.widgets.hovered.bg_fill = PERMUKAAN;
    visuals.widgets.hovered.bg_stroke = Stroke::new(1.0, TEKS_REDUP);
    visuals.widgets.hovered.fg_stroke = Stroke::new(1.0, TEKS);

    visuals.widgets.active.bg_fill = GARIS;
    visuals.widgets.active.bg_stroke = Stroke::new(1.0, TEKS);
    visuals.widgets.active.fg_stroke = Stroke::new(1.0, TEKS);

    ctx.set_visuals(visuals);
}
