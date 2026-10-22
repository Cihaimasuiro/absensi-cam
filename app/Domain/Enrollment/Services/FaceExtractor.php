<?php

namespace App\Domain\Enrollment\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class FaceExtractor
{
    /**
     * Ekstrak face embedding 512-d yang ternormalisasi (2048 byte mentah) dari sebuah foto.
     *
     * @param string $imagePath Path filesystem absolut atau relatif ke foto.
     * @return string String biner tepat 2048 byte (512 little-endian IEEE-754 f32).
     * @throws RuntimeException Saat ekstraksi gagal, wajah tidak valid, atau ukuran embedding tidak cocok.
     */
    public function extract(string $imagePath): string
    {
        $bin = config('app.face_extract_bin');
        if (empty($bin)) {
            $bin = base_path('clients/edge-engine-rs/target/release/face-extract' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''));
        }
        $modelsDir = config('app.face_models_dir') ?: base_path('models');
        $ortPath = config('app.ort_dylib_path') ?: null;

        if (!Process::isRecording() && !file_exists($imagePath)) {
            throw new RuntimeException("Face extraction image file not found at '{$imagePath}'.");
        }

        if (!Process::isRecording() && !file_exists($bin)) {
            throw new RuntimeException(
                "Face extractor binary not found at '{$bin}'. " .
                "Please compile the server extractor via 'cargo build --release --bin face-extract' or configure FACE_EXTRACT_BIN in .env."
            );
        }

        $command = [$bin, '--image', $imagePath];

        if (!empty($modelsDir) && (Process::isRecording() || is_dir($modelsDir))) {
            $command[] = '--models-dir';
            $command[] = $modelsDir;
        }

        if (!empty($ortPath)) {
            $command[] = '--ort-path';
            $command[] = $ortPath;
        }

        $result = Process::path(base_path())
            ->timeout(120)
            ->run($command);


        if (!$result->successful()) {
            $errorOutput = trim($result->errorOutput());
            $errorMsg = 'Extraction failed';
            if (!empty($errorOutput)) {
                $jsonErr = json_decode($errorOutput, true);
                if (is_array($jsonErr) && isset($jsonErr['error'])) {
                    $errorMsg = $jsonErr['error'];
                } else {
                    $errorMsg = $errorOutput;
                }
            }
            throw new RuntimeException("Face extraction failed: {$errorMsg}");
        }

        // Ambil baris terakhir yang tidak kosong dari stdout untuk menghindari log stdout yang tidak perlu
        $stdout = trim($result->output());
        $lines = array_values(array_filter(explode("\n", str_replace("\r", "", $stdout)), fn($l) => trim($l) !== ''));
        $lastLine = !empty($lines) ? end($lines) : '';

        $data = json_decode($lastLine, true);
        if (!$data || empty($data['success']) || empty($data['embedding_b64'])) {
            $err = $data['error'] ?? 'Invalid JSON output from face extractor';
            throw new RuntimeException("Face extraction error: {$err}");
        }

        $rawBytes = base64_decode($data['embedding_b64'], true);
        if ($rawBytes === false || strlen($rawBytes) !== 2048) {
            $len = $rawBytes === false ? 0 : strlen($rawBytes);
            throw new RuntimeException("Invalid embedding size. Expected 2048 bytes (512d), got {$len}");
        }

        return $rawBytes;
    }
}
