# TFT display stream protocol

> Camera preview and static images from the Orange Pi to an Arduino Uno driving a MAR2406 TFT.

## 0. Tujuan dan ekspektasi (jangan dilanggar)
- **Mode A, Live preview kamera:** 160×120 RGB332 (1 byte/piksel) dengan *ordered dithering*. Ditampilkan 1× di tengah layar atau di-upscale 2× oleh Uno ke layar penuh. Target ≥ 3 FPS (stretch 5 FPS). **Ini bukan kualitas HDMI**, hanya panduan memosisikan wajah.
- **Mode B, Gambar statis** (logo, foto, splash): RGB565 320×240 penuh, ±1,6 detik di 1 Mbps. Kualitasnya bagus karena ini gambar diam.
- Jalur JSON yang ada (`display`, `standby`, `io`) **tidak boleh rusak**. Fitur ini mati secara default.
- Semua angka FPS di bawah adalah estimasi dan **harus diukur di Fase 1**. Jangan mengklaim angka yang belum terukur.

## 1. Aturan wajib
1. **Satu pemilik port serial.** Saat ini `ArduinoBridge` dan `TftRleStreamer` sama-sama membuka `ARDUINO_PORT` (lihat `main.rs` sekitar baris 426–460). Hasilnya dua handle ke satu tty, dan byte JSON bisa menyelip di tengah frame. Buat satu `SerialLink` dengan satu thread penulis. `ArduinoBridge` mengirim lewat antrean milik `SerialLink`.
2. Default `ARDUINO_BAUD=115200` dan `ARDUINO_STREAM=0`. Baud 1000000 baru dipakai setelah Fase 1 lulus. Jangan pernah memakai 2 Mbps sebagai default.
3. Jangan memakai skrip Python untuk mem-patch `main.rs`. Edit langsung, commit kecil per fase, dan push tiap fase.
4. Firmware C++ tanpa `malloc`, `new`, atau `String`. Rust memakai `#![forbid(unsafe_code)]` di crate streamer.

## 2. Spesifikasi protokol v3

**Prinsip:** byte `0xFF` tidak pernah muncul di teks UTF-8, jadi JSON dan paket biner bisa berbagi kabel tanpa ambiguitas.

| Offset | Field | Keterangan |
|---|---|---|
| 0–1 | SYNC | `0xFF 0xAA` |
| 2 | TYPE | `0x01` INIT, `0x02` DATA, `0x03` END, `0x0F` ABORT |
| 3 | SEQ | naik 1 per paket (wrap 256) |
| 4 | LEN | payload ≤ **80** byte |
| 5.. | PAYLOAD | lihat di bawah |
| 5+LEN | XOR | XOR dari TYPE, SEQ, LEN, dan seluruh PAYLOAD |

- **INIT** (LEN=10): `x(2) y(2) w(2) h(2) fmt(1) scale(1)`, big-endian. `fmt`: 0 = RGB332, 1 = RGB565 (hi lalu lo). `scale`: 1 atau 2 (2 = piksel dan baris digandakan di Uno). Layar yang terisi adalah `w*scale × h*scale`. Validasi harus masuk dalam 320×240.
- **DATA:** potongan byte piksel secara raster, berurutan. Jumlah total yang diharapkan = `w*h*bpp`.
- **END** (LEN=0) menutup frame. **ABORT** (LEN=0) menutup frame kapan pun.
- **Balasan Uno → SBC, 3 byte:** `0xA5, 0x06|0x15, seq` (ACK atau NAK). Awalan `0xA5` membedakannya dari baris teks `{"evt":...}`.
- **Flow control:** maksimum **2 paket belum di-ACK** (≈172 byte, di bawah ring buffer 256 B). SBC menunggu ACK maksimal 30 ms.
- **Tidak ada retransmit.** Frame itu sekali pakai. Kalau ada NAK, timeout, atau SEQ loncat, SBC mengirim ABORT dan memulai frame berikutnya. Uno membuang frame dan kembali mencari SYNC.
- **Timeout di Uno:** kalau sedang di dalam frame dan tidak ada byte selama 100 ms, Uno otomatis abort (CS dilepas, kembali ke mode teks). **Dilarang `while(!available())` tanpa timeout.**
- **Prioritas JSON:** untuk `display`, `standby`, dan `io`, penulis SBC menyelesaikan paket yang sedang berjalan, mengirim ABORT, lalu JSON, lalu menahan preview 1,5 detik (atau selama status hasil tampil) agar status tidak tertimpa. Uno juga membatalkan frame sendiri begitu menerima baris JSON lengkap.

## 3. Firmware (`clients/arduino-display/`)
1. **Jalur tulis TFT yang benar** (bug utama versi sekarang). Pustaka MCUFRIEND hanya mengatur window dan **tidak** mengirim `0x2C`, serta melepas CS. Urutan yang benar:
   ```cpp
   tft.setAddrWindow(x0, y0, x1, y1);
   CS_LOW;  CD_LOW;  write8(0x2C);  CD_HIGH;   // mulai memory write
   /* ... stream piksel via write8 ... */
   CS_HIGH;                                     // saat END/ABORT/error/timeout
   ```
   Pin shield Uno standar: RD=A0 (PC0, tetap HIGH), WR=A1 (PC1), CD/RS=A2 (PC2), CS=A3 (PC3). Verifikasi dengan membaca `utility/mcufriend_shield.h` bagian ATmega328. Data bus: PB0–1 untuk D0–D1, PD2–7 untuk D2–D7. Pertahankan bit lain (PD0/1 = UART, PB2–4 = LED/buzzer).
2. **UART:** ISR `USART_RX_vect` sendiri, ring buffer **256 byte dengan indeks `uint8_t`** (otomatis atomik di AVR). Catat `FE0` dan `DOR0` ke penghitung error. **Hapus penggunaan `Serial`** (Arduino core) agar tidak bentrok. Buat `uart_write()` sendiri untuk `evt` dan ACK.
3. **Parser byte-per-byte (state machine):** IDLE_TEXT → SYNC → HDR → PAYLOAD → CHK. Taruh di `proto.h` murni (tanpa dependensi Arduino) agar bisa dikompilasi dan di-fuzz di host dengan g++.
4. **Konversi RGB332 → RGB565 dengan rumus yang benar:**
   ```cpp
   hi = (c & 0xE0) | ((c >> 3) & 0x18) | ((c >> 2) & 0x07);
   lo = ((c & 0x1C) << 3) | ((c & 0x03) << 3) | ((c & 0x03) << 1) | ((c & 0x03) >> 1);
   ```
   Uji di host: `0xFF→0xFFFF`, `0x00→0x0000`, `0xE0→0xF800`, `0x1C→0x07E0`, `0x03→0x001F`. Boleh diganti tabel PROGMEM 256 entri kalau hasil benchmark lebih cepat.
5. **Upscale 2×:** buffer baris `uint8_t row[160]` di SRAM. Untuk tiap baris sumber, tulis piksel yang digandakan horizontal dua kali (window tinggi 2 baris).
6. **Anggaran SRAM:** ring 256 + `json_buf` 160 + `row` 160 + `history` ≈248 ≈ 824 B. Ukur dengan `avr-size`, batas Data+BSS ≤ 1.200 B. Ganti `StaticJsonDocument<256>` jadi 192 kalau stack tipis.
7. **Verifikasi driver LCD.** Firmware sekarang memaksa ID `0xD3D3 → 0x9486`. Layar 2,4″ 320×240 lazimnya ILI9341 (`0x9341`). Cetak `readID()` di `{"evt":"ready","lcd_id":"0x...","fw":"2.1.0"}` dan konfirmasi dengan *selftest*. Siapkan opsi `TFT_BGR` (tukar R/B) dan orientasi `setRotation`.

## 4. Sisi SBC (`engine-hardware` dan `engine-cli`, Rust)
1. **`SerialLink`:** satu pemilik port (`serialport` dengan `try_clone` untuk thread baca dan tulis). Antrean prioritas: JSON > paket frame. Thread pembaca mem-parse `0xA5 xx seq` (ACK/NAK) dan mencatat baris `{"evt"...}`. Reconnect otomatis.
2. **Konversi frame (di thread preview, bukan thread vision):**
   - Downscale 640×480 → 160×120 dengan **box filter 4×4** (bukan nearest-neighbour).
   - Ke RGB332 dengan **ordered dither Bayer 4×4**. Ini memperbaiki *banding* secara signifikan.
   - Opsi `TFT_MIRROR=1` (cermin horizontal, lebih natural untuk preview) dan `TFT_GAIN` (angkat kecerahan kalau kamera gelap).
3. **Kebijakan frame:** *latest-frame-wins* dengan kanal kapasitas 1 (`try_send`). Streamer tidak boleh memblokir loop kamera atau AI.
4. **Pacing:** kirim frame berikutnya setelah END di-ACK, plus batas atas `TFT_PREVIEW_FPS` (default 4). Ganti hardcode 100 ms di `main.rs`.
5. **Env:** `ARDUINO_STREAM` (0/1), `ARDUINO_BAUD`, `TFT_PREVIEW_FPS`, `TFT_SCALE` (1/2), `TFT_MIRROR`, `TFT_BGR`.
6. **Metrik** (log per 60 detik): FPS efektif, ACK/NAK/timeout, byte terkirim, jumlah frame yang dibuang.
7. **Mode B:** perintah `show_image` mengirim RGB565 320×240 (atau area bebas) dengan protokol yang sama, untuk logo atau foto hasil absensi.

## 5. Urutan kerja dan gerbang

| Fase | Pekerjaan | Gerbang lulus |
|---|---|---|
| **0 Selftest** | Perintah `{"cmd":"selftest"}` menggambar bar warna (R, G, B, putih, gradasi) lewat jalur tulis baru (poin 3.1). Cetak `lcd_id`. | Foto layar: warna dan orientasi benar, ID terverifikasi |
| **1 Benchmark** | Pakai `serial_bench` yang sudah ada di repo. Tambah ukuran **throughput gambar Uno sendiri** (`micros()` untuk 160×120 pada scale 1 dan 2 dengan beban ISR + Timer0 aktif). Uji 115200 / 1M / 2M, 5 menit. | Pilih baud (error ≤ 0,1%, `DOR0`=0). Tabel keputusan: baud, scale, ukuran window |
| **2 Protokol host** | Crate protokol + decoder simulator + `proptest`/fuzz (byte hilang, bit flip, JSON di tengah, frame terpotong). Vektor uji (golden) dipakai bersama firmware. | Resync ≤ 1 paket, tanpa panik |
| **3 Firmware v3** | Poin 3.1–3.7. Hapus `Serial`. Uji parser di host dengan g++. | AC-2, AC-5 |
| **4 SBC streamer** | Poin 4.1–4.6, tanpa mengubah alur absensi. | AC-3, AC-4, AC-6 |
| **5 Integrasi** | Pasang di `main.rs`, hapus `TftRleStreamer` lama, satu pemilik port. | AC-7, semua AC |
| **6 (opsional)** | Mode B `show_image`; optimasi inner-loop (asm / write langsung ke port) kalau Fase 1 menunjukkan Uno jadi bottleneck. | — |

## 6. Kriteria penerimaan
- **AC-0:** *selftest* benar secara visual, dan `lcd_id` tercatat.
- **AC-1:** 5 menit pada baud terpilih dengan beban gambar: error ≤ 0,1%, `DOR0`=0, tanpa hang.
- **AC-2:** SRAM Data+BSS ≤ 1.200 B. *Stack high-water mark* (stack painting) menyisakan ≥ 400 B saat QR/JSON/stream bersamaan.
- **AC-3:** ≥ 3 FPS median di 160×120 (scale 2), terukur dari penghitung firmware dan log SBC selama 5 menit.
- **AC-4:** JSON status yang disela saat stream muncul di layar < 50 ms, dan preview berhenti sampai status selesai.
- **AC-5 (ketahanan):** tes kehilangan byte, bit flip, frame terpotong, dan cabut-colok USB. Uno tidak pernah hang dan kembali menerima JSON dalam ≤ 100 ms.
- **AC-6 (isolasi):** FPS kamera tetap ≥ 27, CPU tambahan ≤ 5%, suhu ≤ 72°C, dan kegagalan preview tidak mengganggu alur absensi.
- **AC-7:** `grep` membuktikan hanya satu tempat yang membuka `ARDUINO_PORT`.

## 7. Risiko
- **Uno jadi bottleneck, bukan kabel.** Saya perkirakan scale 2 memakan sekitar 180 siklus per byte (ISR sekitar 45 + parse + 8 tulis bus), sedangkan jatah di 1 Mbps hanya 160 siklus. Jadi 3–4 FPS di scale 2 lebih realistis, dan scale 1 (160×120 di tengah) bisa mendekati 5 FPS. Fase 1 yang memutuskan.
- **Latensi USB-serial klon CH340** (sekitar 1–4 ms per round-trip) bisa membatasi window 2 paket. Kalau terukur buruk, naikkan ring buffer ke 512 B dan window ke 3–4 paket, selama SRAM masih muat.
- **Kualitas tetap terbatas.** Kalau yang dibutuhkan benar-benar mulus dan penuh warna, jalurnya ganti perangkat keras (HDMI asli, layar SPI langsung ke Orange Pi, atau board berformat Uno yang lebih kencang), bukan lebih banyak kode.
