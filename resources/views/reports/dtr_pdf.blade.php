<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan DTR</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-red { color: red; }
    </style>
</head>
<body>
    <h2>Laporan Daily Time Record (DTR)</h2>
    <p>Periode: {{ $start }} s/d {{ $end }}</p>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Nama</th>
                <th>NIP/NIS</th>
                <th>Grup/Kelas</th>
                <th>Masuk</th>
                <th>Keluar</th>
                <th>Durasi (m)</th>
                <th>Terlambat (m)</th>
                <th>Status</th>
                <th>Koreksi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($records as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['group'] }}</td>
                <td>{{ $row['first_in'] }}</td>
                <td>{{ $row['last_out'] }}</td>
                <td>{{ $row['duration_minutes'] }}</td>
                <td class="{{ $row['minutes_late'] > 0 ? 'text-red' : '' }}">{{ $row['minutes_late'] }}</td>
                <td>{{ $row['status'] }}</td>
                <td>{{ $row['is_corrected'] ? 'Ya' : 'Tidak' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
