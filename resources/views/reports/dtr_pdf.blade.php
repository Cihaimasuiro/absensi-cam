<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan DTR</title>
    <style>
        @page { margin: 24px 28px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            line-height: 1.45;
            margin: 0;
        }

        /* ── Letterhead ─────────────────────────────────────────────── */
        .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .letterhead td { border: 0; padding: 0; vertical-align: middle; }
        .letterhead .logo { width: 56px; padding-right: 12px; }
        .letterhead .logo img { width: 52px; height: 52px; display: block; }
        .letterhead h1 {
            margin: 0;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: -0.2px;
            text-transform: uppercase;
        }
        .letterhead .meta { margin: 2px 0 0 0; font-size: 9px; color: #666; }
        .rule { border-bottom: 1.5px solid #1a1a1a; margin-bottom: 12px; }

        /* ── Summary strip ──────────────────────────────────────────── */
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .summary td {
            border: 1px solid #e0e0e0;
            padding: 5px 8px;
            width: 25%;
            background: #fafafa;
        }
        .summary .label { display: block; font-size: 7.5px; color: #777; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary .value { display: block; font-size: 12px; font-weight: bold; color: #111; margin-top: 1px; }

        /* ── Data table ─────────────────────────────────────────────── */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.data th {
            background: #f2f2f2;
            border: 1px solid #d8d8d8;
            padding: 4px 5px;
            text-align: left;
            font-size: 7.5px;
            font-weight: bold;
            color: #444;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.data td {
            border: 1px solid #e4e4e4;
            padding: 3.5px 5px;
            font-size: 8.5px;
            vertical-align: top;
        }
        table.data tbody tr:nth-child(even) td { background: #fbfbfb; }

        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .center { text-align: center; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .late { color: #b42318; font-weight: bold; }
        .muted { color: #888; }

        .status-present { color: #067647; font-weight: bold; }
        .status-late { color: #b54708; font-weight: bold; }
        .status-absent { color: #b42318; font-weight: bold; }

        /* ── Footer ─────────────────────────────────────────────────── */
        .footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #e4e4e4;
            font-size: 7.5px;
            color: #999;
        }
        .footer .right { text-align: right; }
    </style>
</head>
<body>

    {{-- Letterhead --}}
    <table class="letterhead">
        <tr>
            <td class="logo">
                <img src="{{ public_path('images/logo.png') }}" alt="">
            </td>
            <td>
                <h1>Laporan Daily Time Record (DTR)</h1>
                <p class="meta">
                    Smart Absensi Face Recognition System &nbsp;·&nbsp;
                    Periode {{ $start }} s/d {{ $end }}
                </p>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    {{-- Summary --}}
    @php
        $totalRows = count($records);
        $lateRows  = collect($records)->where('minutes_late', '>', 0)->count();
        $corrected = collect($records)->where('is_corrected', true)->count();
    @endphp
    <table class="summary">
        <tr>
            <td>
                <span class="label">Total Baris</span>
                <span class="value">{{ $totalRows }}</span>
            </td>
            <td>
                <span class="label">Terlambat</span>
                <span class="value">{{ $lateRows }}</span>
            </td>
            <td>
                <span class="label">Dikoreksi</span>
                <span class="value">{{ $corrected }}</span>
            </td>
            <td>
                <span class="label">Dicetak</span>
                <span class="value">{{ now()->format('d/m/Y H:i') }}</span>
            </td>
        </tr>
    </table>

    {{-- Data --}}
    <table class="data">
        <thead>
            <tr>
                <th style="width: 9%;">Tanggal</th>
                <th style="width: 22%;">Nama</th>
                <th style="width: 10%;">NIP / NIS</th>
                <th style="width: 14%;">Grup / Kelas</th>
                <th class="center" style="width: 9%;">Masuk</th>
                <th class="center" style="width: 9%;">Keluar</th>
                <th class="num" style="width: 8%;">Durasi</th>
                <th class="num" style="width: 8%;">Telat</th>
                <th style="width: 11%;">Status</th>
                <th class="center" style="width: 7%;">Koreksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $row)
                <tr>
                    <td class="mono">{{ $row['date'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="mono">{{ $row['code'] }}</td>
                    <td>{{ $row['group'] }}</td>
                    <td class="center mono">{{ $row['first_in'] ?: '—' }}</td>
                    <td class="center mono">{{ $row['last_out'] ?: '—' }}</td>
                    <td class="num">{{ $row['duration_minutes'] }}</td>
                    <td class="num {{ $row['minutes_late'] > 0 ? 'late' : 'muted' }}">
                        {{ $row['minutes_late'] > 0 ? $row['minutes_late'] : '—' }}
                    </td>
                    <td class="status-{{ strtolower($row['status']) }}">{{ $row['status'] }}</td>
                    <td class="center {{ $row['is_corrected'] ? 'late' : 'muted' }}">
                        {{ $row['is_corrected'] ? 'Ya' : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="center muted" style="padding: 16px;">
                        Tidak ada data absensi pada periode ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Footer --}}
    <table class="footer" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td>Smart Absensi · Dokumen dihasilkan otomatis oleh sistem</td>
            <td class="right">Periode {{ $start }} s/d {{ $end }}</td>
        </tr>
    </table>

</body>
</html>
