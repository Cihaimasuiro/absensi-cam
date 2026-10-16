<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Models\FaceTemplate;
use App\Domain\Student\Models\Student;
use Illuminate\Support\Facades\Log;

class BiometricDuplicateDetector
{
    public const DEFAULT_THRESHOLD = 0.75;

    public function __construct(
        private readonly FaceEncryptionService $encryptionService
    ) {}

    /**
     * Memeriksa apakah embedding kandidat mirip dengan template aktif lain di sekolah yang sama.
     *
     * @param string $rawEmbedding 2048 bytes binary (512 float32)
     * @param string $studentId ID siswa kandidat (diabaikan dari pencocokan)
     * @param string|null $schoolId ID sekolah (jika null, akan diambil dari data siswa)
     * @param float $threshold Batas ambang kemiripan (default 0.75)
     * @return array{matched_student_id: string, similarity: float, student: ?Student}|null
     */
    public function detect(
        string $rawEmbedding,
        string $studentId,
        ?string $schoolId = null,
        float $threshold = self::DEFAULT_THRESHOLD
    ): ?array {
        if (strlen($rawEmbedding) !== 2048) {
            return null;
        }

        if (!$schoolId) {
            $student = Student::find($studentId);
            $schoolId = $student?->school_id;
        }

        $query = FaceTemplate::query()
            ->whereNull('deleted_at')
            ->where('student_id', '!=', $studentId)
            ->with(['student' => function ($q) {
                $q->select(['id', 'school_id', 'name', 'code', 'classroom_id'])->whereNull('deleted_at');
            }]);

        if ($schoolId) {
            $query->whereHas('student', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)->whereNull('deleted_at');
            });
        }

        $candidates = $query->get(['id', 'student_id', 'embedding_enc', 'model_version']);

        $highestSim = 0.0;
        $bestMatch = null;

        foreach ($candidates as $candidate) {
            if (!$candidate->student) {
                continue;
            }

            try {
                $candidateRaw = $this->encryptionService->decrypt(
                    base64_decode($candidate->embedding_enc),
                    $candidate->student_id,
                    $candidate->model_version
                );

                $sim = self::cosineSimilarity($rawEmbedding, $candidateRaw);

                if ($sim > $highestSim) {
                    $highestSim = $sim;
                    $bestMatch = $candidate;
                }
            } catch (\Throwable $e) {
                Log::warning('BiometricDuplicateDetector failed to decrypt candidate template', [
                    'template_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($bestMatch && $highestSim >= $threshold) {
            return [
                'matched_student_id' => $bestMatch->student_id,
                'similarity' => round($highestSim, 4),
                'student' => $bestMatch->student,
            ];
        }

        return null;
    }

    /**
     * Memindai seluruh template aktif untuk mendeteksi duplikasi di DB.
     *
     * @param string|null $schoolId
     * @param float $threshold
     * @return array<int, array{student_a: Student, student_b: Student, similarity: float}>
     */
    public function auditDuplicates(?string $schoolId = null, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $query = FaceTemplate::query()
            ->whereNull('deleted_at')
            ->with(['student' => function ($q) {
                $q->select(['id', 'school_id', 'name', 'code', 'classroom_id'])->whereNull('deleted_at');
            }]);

        if ($schoolId) {
            $query->whereHas('student', function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)->whereNull('deleted_at');
            });
        }

        $templates = $query->get(['id', 'student_id', 'embedding_enc', 'model_version']);
        $decrypted = [];

        foreach ($templates as $t) {
            if (!$t->student) {
                continue;
            }

            try {
                $raw = $this->encryptionService->decrypt(
                    base64_decode($t->embedding_enc),
                    $t->student_id,
                    $t->model_version
                );
                $decrypted[] = [
                    'student' => $t->student,
                    'raw' => $raw,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        $duplicates = [];
        $count = count($decrypted);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sim = self::cosineSimilarity($decrypted[$i]['raw'], $decrypted[$j]['raw']);
                if ($sim >= $threshold) {
                    $duplicates[] = [
                        'student_a' => $decrypted[$i]['student'],
                        'student_b' => $decrypted[$j]['student'],
                        'similarity' => round($sim, 4),
                    ];
                }
            }
        }

        return $duplicates;
    }

    /**
     * Menghitung cosine similarity dari dua binary embeddings (2048 bytes / 512 float32).
     */
    public static function cosineSimilarity(string $rawA, string $rawB): float
    {
        if (strlen($rawA) !== 2048 || strlen($rawB) !== 2048) {
            return 0.0;
        }

        $floatsA = unpack('g*', $rawA);
        $floatsB = unpack('g*', $rawB);

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 1; $i <= 512; $i++) {
            $a = $floatsA[$i];
            $b = $floatsB[$i];
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        if ($normA < 1e-6 || $normB < 1e-6) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
