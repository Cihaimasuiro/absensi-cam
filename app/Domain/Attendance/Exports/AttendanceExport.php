<?php

namespace App\Domain\Attendance\Exports;

use App\Domain\Attendance\Models\AttendanceLog;
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
        $query = AttendanceLog::query()
            ->with('student:id,employee_number,name,classroom_id', 'student.classroom:id,name')
            ->select(['id', 'student_id', 'device_id', 'captured_at', 'direction', 'score', 'liveness_score', 'time_source'])
            ->orderBy('captured_at', 'desc');

        if ($this->dateFrom) {
            $from = \Carbon\Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay()->setTimezone('UTC');
            $query->where('captured_at', '>=', $from);
        }
        
        if ($this->dateTo) {
            $to = \Carbon\Carbon::parse($this->dateTo, config('app.timezone'))->endOfDay()->setTimezone('UTC');
            $query->where('captured_at', '<=', $to);
        }

        // TODO: department filtering needs to join student -> classroom -> building -> school?
        // Let's omit department for now or handle it via whereHas
        if ($this->department) {
            $query->whereHas('student.classroom', function ($q) {
                $q->where('name', $this->department); // Rough mapping, depends on actual structure
            });
        }
        
        return $query;
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
            $row->student?->employee_number ?? '-',
            $row->student?->name ?? 'Anggota',
            $row->student?->classroom?->name ?? '-',
            $row->device_id,
            $row->captured_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s'),
            $row->score ? round($row->score * 100, 1) . '%' : '-',
            strtoupper($row->direction),
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
