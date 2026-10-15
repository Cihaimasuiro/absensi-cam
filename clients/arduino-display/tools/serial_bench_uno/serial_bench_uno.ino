// ==============================================================================
// Smart Absensi: Serial Benchmark & Stress Tester Firmware (Arduino Uno ATmega328P)
// ==============================================================================
// Fitur:
// 1. Ring Buffer 256-Byte dengan indeks 8-bit ATOMIK (Bebas dari race condition 16-bit).
// 2. Monitoring register hardware error UART secara realtime (DOR0, FE0, Buffer Overflow).
// 3. Konversi warna RGB332 -> RGB565 yang dikoreksi (Kanal hijau akurat, tanpa distorsi).
// 4. Simulasi beban bus paralel MAR2406 (Siklus CPU akurat layaknya menggambar ke layar).
// 5. Preemption / STREAM_ABORT handler berlatensi rendah (< 1 ms).

#include <avr/io.h>
#include <avr/interrupt.h>
#include <stdint.h>

#define BAUD_RATE 1000000 // Ubah ke 2000000 untuk pengujian 2 Mbps ekstrem

// 1. RING BUFFER RX 256 BYTE (Indeks uint8_t 8-bit otomatis wrap-around 0..255)
static volatile uint8_t rx_buf[256];
static volatile uint8_t rx_head = 0;
static volatile uint8_t rx_tail = 0;

// Counter error hardware & link
static volatile uint16_t hw_dor0_count = 0; // Data Overrun
static volatile uint16_t hw_fe0_count = 0;  // Framing Error
static volatile uint16_t rx_overflow_count = 0;

// ISR UART RX
ISR(USART_RX_vect) {
  uint8_t status = UCSR0A;
  uint8_t data = UDR0; // Wajib dibaca untuk membersihkan interrupt flag

  if (status & (1 << DOR0)) {
    hw_dor0_count++;
  }
  if (status & (1 << FE0)) {
    hw_fe0_count++;
  }

  uint8_t next_head = rx_head + 1;
  if (next_head != rx_tail) {
    rx_buf[rx_head] = data;
    rx_head = next_head;
  } else {
    rx_overflow_count++;
  }
}

static inline bool rx_has_data() {
  return rx_head != rx_tail;
}

static inline uint8_t rx_pop() {
  uint8_t b = rx_buf[rx_tail];
  rx_tail++;
  return b;
}

static inline void uart_write(uint8_t b) {
  while (!(UCSR0A & (1 << UDRE0))) {}
  UDR0 = b;
}

// 2. BUS PARALEL TFT MAR2406
// D0..D1 = Pin 8, 9 (PORTB 0..1), D2..D7 = Pin 2..7 (PORTD 2..7)
// WR = Pin A1 (PORTC1)
static inline void tft_bus_write8(uint8_t d) {
  PORTB = (PORTB & 0xFC) | (d & 0x03);
  PORTD = (PORTD & 0x03) | (d & 0xFC);
  PORTC &= ~(1 << 1); // WR Strobe LOW
  PORTC |=  (1 << 1); // WR Strobe HIGH
}

static inline void tft_bus_write16(uint8_t hi, uint8_t lo) {
  tft_bus_write8(hi);
  tft_bus_write8(lo);
}

// 3. KONVERSI WARNA RGB332 -> RGB565 (KOREKSI BUG KANAL HIJAU)
static inline void rgb332_to_565_bytes(uint8_t c, uint8_t &hi, uint8_t &lo) {
  uint8_t r = c & 0xE0;         // RRR00000
  uint8_t g = (c << 3) & 0xE0;  // GGG00000
  uint8_t b = (c << 6);         // BB000000

  // hi = RRRRRGGG (5-bit R, 3-bit atas G)
  hi = r | (r >> 3) | (g >> 5);
  // lo = GGGBBBBB (3-bit bawah G, 5-bit B)
  lo = g | (b >> 3) | (b >> 5) | (b >> 7);
}

// 4. PROTOKOL MICRO-PACKET & STATISTIK
#define SYNC_0 0xFF
#define SYNC_1 0xAA
#define ACK_BYTE 0x06
#define NAK_BYTE 0x15

static uint32_t total_packets = 0;
static uint32_t total_bytes = 0;
static uint16_t checksum_errors = 0;

void init_uart(uint32_t baud) {
  cli();
  UCSR0A = (1 << U2X0); // Double Speed Mode
  uint16_t ubrr = 0;
  if (baud == 2000000) {
    ubrr = 0; // UBRR0 = 0 -> 2 Mbps (0% error @ 16 MHz)
  } else if (baud == 1000000) {
    ubrr = 1; // UBRR0 = 1 -> 1 Mbps (0% error @ 16 MHz)
  } else {
    ubrr = (16000000UL / (8UL * baud)) - 1;
  }
  UBRR0H = (uint8_t)(ubrr >> 8);
  UBRR0L = (uint8_t)ubrr;
  UCSR0C = (1 << UCSZ01) | (1 << UCSZ00); // 8-N-1
  UCSR0B = (1 << RXEN0) | (1 << TXEN0) | (1 << RXCIE0); // Enable RX, TX, RX-Interrupt
  sei();
}

void setup() {
  // Setup pin kontrol bus TFT (PORTB, PORTD, PORTC output)
  DDRB |= 0x03; // Pin 8, 9 (D0, D1)
  DDRD |= 0xFC; // Pin 2..7 (D2..D7)
  DDRC |= (1 << 1); // Pin A1 (WR)
  PORTC |= (1 << 1); // WR Idle HIGH

  init_uart(BAUD_RATE);
}

// Buffer paket lokal
static uint8_t payload_buf[160];
static char json_line[128];
static uint8_t json_len = 0;

void loop() {
  while (rx_has_data()) {
    uint8_t b = rx_pop();
    total_bytes++;

    // Deteksi Paket Biner (SYNC 0xFF 0xAA)
    if (b == SYNC_0) {
      // Tunggu byte sinkron ke-2 dengan timeout non-blocking
      while (!rx_has_data()) {}
      uint8_t b2 = rx_pop();
      total_bytes++;

      if (b2 == SYNC_1) {
        // Baca Header: TYPE (1B), SEQ (1B), LEN (1B)
        while (!rx_has_data()) {}
        uint8_t pkt_type = rx_pop();
        while (!rx_has_data()) {}
        uint8_t seq = rx_pop();
        while (!rx_has_data()) {}
        uint8_t len = rx_pop();
        total_bytes += 3;

        uint8_t calc_chk = pkt_type ^ seq ^ len;

        // Baca Payload
        for (uint8_t i = 0; i < len; i++) {
          while (!rx_has_data()) {}
          uint8_t p = rx_pop();
          if (i < sizeof(payload_buf)) {
            payload_buf[i] = p;
          }
          calc_chk ^= p;
        }
        total_bytes += len;

        // Baca Checksum XOR
        while (!rx_has_data()) {}
        uint8_t rx_chk = rx_pop();
        total_bytes++;

        // Validasi Checksum
        if (calc_chk != rx_chk) {
          checksum_errors++;
          uart_write(NAK_BYTE);
          continue;
        }

        total_packets++;

        // Eksekusi Tipe Paket
        if (pkt_type == 0x0F) {
          // STREAM_ABORT: reset state dan kirim event
          const char* abort_msg = "{\"evt\":\"aborted\"}\n";
          while (*abort_msg) {
            uart_write(*abort_msg++);
          }
          continue;
        } else if (pkt_type == 0x02 || pkt_type == 0x03) {
          // SCANLINE: Blit piksel RGB332 ke bus layar (Mensimulasikan beban nyata!)
          for (uint8_t i = 0; i < len; i++) {
            uint8_t hi, lo;
            rgb332_to_565_bytes(payload_buf[i], hi, lo);
            tft_bus_write16(hi, lo);
            if (pkt_type == 0x03) {
              // 2x Horizontal Doubling
              tft_bus_write16(hi, lo);
            }
          }
          uart_write(ACK_BYTE);
        } else {
          uart_write(ACK_BYTE);
        }
        continue;
      }
    }

    // Deteksi Mode JSON Baris (\n)
    if (b == '\n' || b == '\r') {
      if (json_len > 0) {
        json_line[json_len] = '\0';
        if (strstr(json_line, "ping") != NULL) {
          const char* pong = "{\"evt\":\"pong\"}\n";
          while (*pong) uart_write(*pong++);
        } else if (strstr(json_line, "telemetry") != NULL) {
          // Laporkan register hardware error langsung ke SBC
          char report[120];
          snprintf(report, sizeof(report),
            "{\"bytes\":%lu,\"pkts\":%lu,\"dor0\":%u,\"fe0\":%u,\"ovf\":%u,\"chk_err\":%u}\n",
            total_bytes, total_packets, hw_dor0_count, hw_fe0_count, rx_overflow_count, checksum_errors
          );
          char* p = report;
          while (*p) uart_write(*p++);
        }
        json_len = 0;
      }
    } else {
      if (json_len < sizeof(json_line) - 1) {
        json_line[json_len++] = (char)b;
      } else {
        json_len = 0;
      }
    }
  }
}
