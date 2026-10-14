// -----------------------------------------------------------------------------
// Layout Helper untuk Menjamin Kolom Header & Baris 100% Sejajar Vertikal
// -----------------------------------------------------------------------------

#[derive(Debug, Clone, Copy)]
pub struct TableLayout {
    pub is_compact: bool,
    pub col_x: [f32; 7],
    pub col_w: [f32; 7],
    pub total_w: f32,
}

impl TableLayout {
    pub fn compute(available_width: f32) -> Self {
        let is_compact = available_width < 920.0;

        if is_compact {
            // Mode Kompak (< 920px): Sembunyikan 3 kolom telemetri sekunder (Engine, Suhu CPU, Terakhir).
            // Alokasikan ruang ke Status (90px), Perangkat (fr), Alamat (fr), dan Aksi (150px).
            let col_gap = 12.0;
            let w_status = 90.0;
            let w_action = 150.0;

            let fixed_sum = w_status + w_action + (3.0 * col_gap);
            let avail_for_fr = (available_width - fixed_sum).max(180.0);
            let w_device = avail_for_fr * 0.5;
            let w_address = avail_for_fr * 0.5;

            let col_w = [
                w_status,
                w_device,
                w_address,
                0.0,
                0.0,
                0.0,
                w_action,
            ];

            let mut col_x = [0.0; 7];
            col_x[0] = 0.0;
            col_x[1] = col_x[0] + col_w[0] + col_gap;
            col_x[2] = col_x[1] + col_w[1] + col_gap;
            // 3, 4, 5 tersembunyi
            col_x[3] = 0.0;
            col_x[4] = 0.0;
            col_x[5] = 0.0;
            col_x[6] = col_x[2] + col_w[2] + col_gap;

            Self {
                is_compact,
                col_x,
                col_w,
                total_w: available_width.max(col_x[6] + col_w[6]),
            }
        } else {
            // Mode Penuh (>= 920px): Tampilkan semua 7 kolom.
            // Kolom Engine diperlebar dari 105px -> 125px agar 'v0.2.8 · usang' tidak menempel dengan '—'.
            let col_gap = 14.0;
            let w_status = 100.0;
            let w_engine = 125.0;
            let w_temp = 85.0;
            let w_time = 105.0;
            let w_action = 160.0;

            let fixed_sum = w_status + w_engine + w_temp + w_time + w_action + (6.0 * col_gap);
            let avail_for_fr = (available_width - fixed_sum).max(200.0);
            let w_device = avail_for_fr * 0.5;
            let w_address = avail_for_fr * 0.5;

            let col_w = [
                w_status,
                w_device,
                w_address,
                w_engine,
                w_temp,
                w_time,
                w_action,
            ];

            let mut col_x = [0.0; 7];
            let mut cur = 0.0;
            for i in 0..7 {
                col_x[i] = cur;
                cur += col_w[i] + col_gap;
            }

            Self {
                is_compact,
                col_x,
                col_w,
                total_w: available_width.max(cur - col_gap),
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_table_layout_alignment() {
        // Mode Penuh (>= 920px)
        let layout = TableLayout::compute(1000.0);
        assert!(!layout.is_compact);
        assert_eq!(layout.col_w[0], 100.0); // Status
        assert_eq!(layout.col_w[3], 125.0); // Engine
        assert_eq!(layout.col_w[4], 85.0);  // Suhu CPU
        assert_eq!(layout.col_w[5], 105.0); // Terakhir
        assert_eq!(layout.col_w[6], 160.0); // Aksi

        // Kolom 1 dan 2 harus proporsional dan sama lebar
        assert_eq!(layout.col_w[1], layout.col_w[2]);
        // Jarak antar kolom konsisten
        assert_eq!(layout.col_x[1], layout.col_x[0] + layout.col_w[0] + 14.0);

        // Mode Kompak (< 920px)
        let compact = TableLayout::compute(800.0);
        assert!(compact.is_compact);
        assert_eq!(compact.col_w[0], 90.0);
        assert_eq!(compact.col_w[3], 0.0); // Engine disembunyikan
        assert_eq!(compact.col_w[4], 0.0); // Suhu CPU disembunyikan
        assert_eq!(compact.col_w[5], 0.0); // Terakhir disembunyikan
        assert_eq!(compact.col_w[6], 150.0); // Aksi 150px
        assert_eq!(compact.col_w[1], compact.col_w[2]);
        assert_eq!(compact.col_x[6], compact.col_x[2] + compact.col_w[2] + 12.0);
    }
}
