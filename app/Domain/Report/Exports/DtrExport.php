<?php

namespace App\Domain\Report\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DtrExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $dtrRecords;

    public function __construct(Collection $dtrRecords)
    {
        $this->dtrRecords = $dtrRecords;
    }

    public function collection()
    {
        return $this->dtrRecords;
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'Nama',
            'NIP/NIS',
            'Grup/Kelas',
            'Jam Masuk',
            'Jam Keluar',
            'Durasi (Menit)',
            'Terlambat (Menit)',
            'Status',
            'Koreksi',
        ];
    }

    public function map($record): array
    {
        $sanitize = function ($val) {
            return preg_match('/^[=\-+\@\t]/', (string)$val) ? "'" . $val : $val;
        };

        return [
            $record['date'],
            $sanitize($record['name']),
            $sanitize($record['code']),
            $sanitize($record['group']),
            $record['first_in'],
            $record['last_out'],
            $record['duration_minutes'],
            $record['minutes_late'],
            $record['status'],
            $record['is_corrected'] ? 'Ya' : 'Tidak',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
