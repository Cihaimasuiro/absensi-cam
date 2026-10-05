#!/usr/bin/env node
/**
 * scripts/run-edge.mjs
 *
 * Cross-platform launcher for the Edge Engine Python process.
 * Used by `npm run dev:edge` — NOT used in production (Orange Pi uses systemd).
 *
 * Selects the correct venv binary path per platform:
 *   Windows → clients/edge-engine/venv/Scripts/python.exe
 *   Linux   → clients/edge-engine/venv/bin/python
 *   macOS   → clients/edge-engine/venv/bin/python3 (fallback)
 */

import { spawnSync } from 'child_process';
import { existsSync } from 'fs';
import { join } from 'path';
import { fileURLToPath } from 'url';
import { dirname } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT      = join(__dirname, '..');
const ENGINE    = join(ROOT, 'clients', 'edge-engine');

function resolvePython() {
    const candidates =
        process.platform === 'win32'
            ? ['venv/Scripts/python.exe']
            : ['venv/bin/python', 'venv/bin/python3'];

    for (const rel of candidates) {
        const abs = join(ENGINE, rel);
        if (existsSync(abs)) return abs;
    }

    console.error('[run-edge] ERROR: Python venv tidak ditemukan.');
    console.error(
        '[run-edge] Jalankan dulu:  cd clients/edge-engine && python -m venv venv && venv/bin/pip install -r requirements.txt',
    );
    process.exit(1);
}

const python = resolvePython();

console.log(`[run-edge] Menggunakan Python: ${python}`);

const result = spawnSync(python, ['engine.py'], {
    cwd:   ENGINE,
    stdio: 'inherit',
    env:   { ...process.env },
});

process.exit(result.status ?? 1);
