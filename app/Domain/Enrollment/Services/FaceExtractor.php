<?php

namespace App\Domain\Enrollment\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class FaceExtractor
{
    /**
     * Extract 512-d normalized face embedding (2048 raw bytes) from an image photo.
     *
     * @param string $imagePath Absolute or relative filesystem path to the photo.
     * @return string Binary string of exactly 2048 bytes (512 little-endian IEEE-754 f32).
     * @throws RuntimeException When extraction fails, face is invalid, or embedding size mismatch.
     */
    public function extract(string $imagePath): string
    {
        $bin = config(
            'app.face_extract_bin',
            base_path('clients/edge-engine-rs/target/release/face-extract' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))
        );
        $modelsDir = config('app.face_models_dir', base_path('models'));
        $ortPath = config('app.ort_dylib_path');

        $command = [$bin];
        if (str_contains(basename($bin), 'smart-absensi-sync') || str_contains(basename($bin), 'engine-cli')) {
            $command[] = 'extract';
        }
        $command[] = '--image';
        $command[] = $imagePath;
        $command[] = '--models-dir';
        $command[] = $modelsDir;

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

        // Take the last non-empty line of stdout to avoid any extraneous stdout logs
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
