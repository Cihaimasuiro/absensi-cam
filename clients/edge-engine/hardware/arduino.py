"""
hardware/arduino.py — Bridge komunikasi serial ke Arduino Uno.

Arduino mengelola:
  - LCD TFT 2.4" (tampilan status absensi)
  - Buzzer + LED (respons fisik)
  - DS3231 RTC
  - PCF8574 I2C IO Expander

Komunikasi: USB Serial (JSON lines), 115200 baud.
"""

import json
import logging
import time
import serial

from config.settings import ARDUINO_PORT, ARDUINO_BAUD


class ArduinoBridge:
    def __init__(self, port: str = ARDUINO_PORT, baudrate: int = ARDUINO_BAUD):
        self.port = port
        self.baudrate = baudrate
        self._serial: serial.Serial | None = None

    def connect(self) -> bool:
        try:
            self._serial = serial.Serial(self.port, self.baudrate, timeout=1)
            time.sleep(2)  # Tunggu auto-reset Arduino
            logging.info(f"[Arduino] Terhubung di {self.port}")
            return True
        except Exception as e:
            logging.warning(f"[Arduino] Gagal terhubung ({self.port}): {e}")
            return False

    def send(self, payload: dict) -> None:
        if self._serial and self._serial.is_open:
            try:
                self._serial.write((json.dumps(payload) + "\n").encode("utf-8"))
            except Exception as e:
                logging.error(f"[Arduino] Gagal kirim: {e}")

    def standby(self) -> None:
        self.send({"cmd": "standby", "msg": "Sistem Siap!"})

    def recognized(self, name: str, dept: str, timestamp: str) -> None:
        self.send({"cmd": "display", "status": "RECOGNIZED", "name": name, "dept": dept, "time": timestamp})
        self.send({"cmd": "io", "led": "green", "buzzer": "2short"})

    def unknown(self) -> None:
        self.send({"cmd": "display", "status": "UNKNOWN"})
        self.send({"cmd": "io", "led": "red", "buzzer": "1long"})

    def close(self) -> None:
        if self._serial and self._serial.is_open:
            self._serial.close()
