<?php

namespace App\Console\Commands;

use App\Domain\Enrollment\Services\BiometricDuplicateDetector;
use Illuminate\Console\Command;

class AuditBiometricDuplicates extends Command
{
    protected $signature = 'biometric:audit-duplicates
                            {--threshold=0.75 : Nilai ambang batas kemiripan (default 0.75)}
                            {--school= : Filter berdasarkan ID sekolah}';

    protected $description = 'Audit seluruh template wajah aktif untuk mendeteksi potensi duplikasi biometrik';

    public function handle(BiometricDuplicateDetector $detector): int
    {
        $threshold = (float) $this->option('threshold');
        $schoolId = $this->option('school') ?: null;

        $this->info("Memulai audit duplikasi biometrik (threshold: {$threshold})...");

        $duplicates = $detector->auditDuplicates($schoolId, $threshold);

        if (empty($duplicates)) {
            $this->info('Audit selesai: Tidak ditemukan duplikasi wajah yang melebihi ambang batas.');
            return self::SUCCESS;
        }

        $this->warn(sprintf('Ditemukan %d pasang kandidat duplikasi wajah:', count($duplicates)));

        foreach ($duplicates as $i => $dup) {
            $a = $dup['student_a'];
            $b = $dup['student_b'];
            $sim = number_format($dup['similarity'] * 100, 2);
            $this->line(sprintf('  [%d] Siswa A: %s (%s)', $i + 1, $a->name, $a->code));
            $this->line(sprintf('      Siswa B: %s (%s) | Kemiripan: %s%%', $b->name, $b->code, $sim));
        }

        $this->comment('Catatan: Periksa kedua siswa di atas. Jika merupakan orang yang sama, hapus salah satu template/siswa.');

        return self::SUCCESS;
    }
}
