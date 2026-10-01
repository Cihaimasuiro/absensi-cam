<?php

namespace App\Domain\Attendance\Exports;

use App\Domain\Attendance\Models\Attendance;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo   = null,
        private readonly ?string $department = null,
    ) {}

    public function query()
    {
        return Attendance::query()
            ->with('member:id,employee_number')
            ->when($this->dateFrom, fn ($q) => $q->whereDate('attended_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->whereDate('attended_at', '<=', $this->dateTo))
            ->when($this->department, fn ($q) => $q->where('department', $this->department))
            ->select(['id', 'member_id', 'name', 'department', 'device_id', 'attended_at', 'confidence', 'status'])
            ->orderBy('attended_at', 'desc');
    }

    public function headings(): array
    {
        return ['#', 'NIK', 'Nama', 'Departemen', 'Perangkat', 'Waktu Absen', 'Confidence', 'Status'];
    }

    public function map($row): array
    {
        static $no = 0;
        $no++;

        return [
            $no,
            $row->member?->employee_number ?? '-',
            $row->name,
            $row->department ?? '-',
            $row->device_id,
            $row->attended_at->format('d/m/Y H:i:s'),
            $row->confidence ? round($row->confidence * 100, 1) . '%' : '-',
            strtoupper($row->status),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Data Absensi';
    }
}
