@echo off
setlocal enabledelayedexpansion

echo ===========================================
echo    Smart Absensi - Development Launcher
echo ===========================================
echo.

:: ─── Prerequisite Checks ───────────────────────────────────────────────────

:: Check Laravel .env
if not exist ".env" (
    echo [ERROR] File .env tidak ditemukan.
    echo         Jalankan: cp .env.example .env ^&^& php artisan key:generate
    pause & exit /b 1
)

:: Check if venv exists for edge-engine
if not exist "clients\edge-engine\venv\Scripts\python.exe" (
    echo [ERROR] Python venv Edge Engine belum dibuat.
    echo         Jalankan: cd clients\edge-engine ^&^& python -m venv venv ^&^& venv\Scripts\pip install -r requirements.txt
    pause & exit /b 1
)

:: ─── Start Services ────────────────────────────────────────────────────────

echo [INFO] Menjalankan Laravel, Vite, dan Edge Engine dalam 1 terminal...
node node_modules\concurrently\dist\bin\index.js --kill-others -c "cyan,magenta,yellow" -n "LARAVEL,VITE,EDGE" "php artisan serve" "node node_modules\vite\bin\vite.js" "cd clients\edge-engine && .\venv\Scripts\python.exe engine.py"

echo.
echo [INFO] Semua layanan dihentikan.
pause
