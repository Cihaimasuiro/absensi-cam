<?php

namespace App\Domain\Student\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class StudentsTemplateExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    public function headings(): array
    {
        return [
            'nis',
            'nama',
            'email',
            'telepon',
            'role'
        ];
    }

    public function array(): array
    {
        return [
            [
                '202401',
                'Contoh Siswa A',
                'siswa.a@sekolah.sch.id',
                '081234567890',
                'student'
            ],
            [
                '202402',
                'Contoh Guru B',
                'guru.b@sekolah.sch.id',
                '081987654321',
                'teacher'
            ]
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1    => ['font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F46E5']]],
        ];
    }
}
