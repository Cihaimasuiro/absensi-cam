/**
 * arduino-display-minimal-test.ino  — bisect step 2
 *
 * Adds Wire.h + gBuf[280] to the working minimal test.
 * Expected: screen turns RED with "DISPLAY OK"
 * If white → Wire.h or gBuf RAM pressure breaks TFT init.
 * If red   → problem is elsewhere in the full firmware.
 */

#include <avr/wdt.h>
void wdt_init() __attribute__((naked)) __attribute__((section(".init3")));
void wdt_init() { MCUSR = 0; wdt_disable(); }

#include <Wire.h>           // ← does including this break things?
#include <Adafruit_GFX.h>
#include <MCUFRIEND_kbv.h>

MCUFRIEND_kbv tft;

static char gBuf[280];      // ← 280-byte buffer simulating full firmware RAM

void setup() {
  Serial.begin(9600);
  delay(500);

  uint16_t id = tft.readID();
  Serial.print(F("readID()=0x")); Serial.println(id, HEX);
  if (id == 0xD3D3) id = 0x9341;

  tft.begin(id);
  tft.setRotation(1);
  tft.fillScreen(0xF800);   // RED

  Wire.begin();             // ← called after TFT, same order as full firmware

  tft.setTextColor(0xFFFF);
  tft.setTextSize(3);
  tft.setCursor(40, 100);
  tft.print(F("DISPLAY OK"));

  tft.setTextSize(2);
  tft.setTextColor(0xFFE0);
  tft.setCursor(80, 150);
  tft.print(F("ID=0x")); tft.print(id, HEX);

  // Print free RAM
  extern int __heap_start, *__brkval;
  int freeRam = (int)&freeRam - (__brkval == 0 ? (int)&__heap_start : (int)__brkval);
  tft.setCursor(80, 175);
  tft.print(F("Free RAM: ")); tft.print(freeRam);

  Serial.print(F("Free RAM: ")); Serial.println(freeRam);
  Serial.println(F("Done."));
}

void loop() {}
