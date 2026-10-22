<?php

namespace Tests\Unit;

use App\Domain\Enrollment\Services\FaceExtractor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class FaceExtractorTest extends TestCase
{
    public function test_extract_passes_correct_arguments_and_parses_last_line_embedding(): void
    {
        Process::fake([
            '*' => function ($process) {
                $command = $process->command;
                // Assert required arguments
                $this->assertContains('--image', $command);
                $this->assertContains('/tmp/test_photo.jpg', $command);
                $this->assertContains('--models-dir', $command);
                $this->assertContains('/custom/models', $command);
                $this->assertContains('--ort-path', $command);
                $this->assertContains('/usr/lib/libonnxruntime.so', $command);

                // Simulate stderr tracing log and stdout with prior log noise and valid JSON on the final line
                $fakeBinary = random_bytes(2048);
                $validJson = json_encode([
                    'success' => true,
                    'embedding_b64' => base64_encode($fakeBinary),
                ]);

                return Process::result(
                    output: "2026-10-22T04:00:00Z INFO loaded models\n[tracing noise line 2]\n{$validJson}\n",
                    errorOutput: '',
                    exitCode: 0
                );
            },
        ]);

        Config::set('app.face_models_dir', '/custom/models');
        Config::set('app.ort_dylib_path', '/usr/lib/libonnxruntime.so');

        $extractor = new FaceExtractor();
        $embedding = $extractor->extract('/tmp/test_photo.jpg');

        $this->assertEquals(2048, strlen($embedding));
    }

    public function test_extract_surfaces_stderr_error_when_process_fails(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: '{"error":"No face detected in photo"}',
                exitCode: 1
            ),
        ]);

        $extractor = new FaceExtractor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Face extraction failed: No face detected in photo');

        $extractor->extract('/tmp/no_face.jpg');
    }

    public function test_extract_throws_on_malformed_json_output(): void
    {
        Process::fake([
            '*' => Process::result(
                output: 'Fatal error: memory corruption',
                errorOutput: '',
                exitCode: 0
            ),
        ]);

        $extractor = new FaceExtractor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Face extraction error: Invalid JSON output from face extractor');

        $extractor->extract('/tmp/corrupt.jpg');
    }

    public function test_extract_throws_on_embedding_dimension_mismatch(): void
    {
        // 1024 bytes instead of required 2048 bytes
        $shortBytes = random_bytes(1024);

        Process::fake([
            '*' => Process::result(
                output: json_encode([
                    'success' => true,
                    'embedding_b64' => base64_encode($shortBytes),
                ]),
                errorOutput: '',
                exitCode: 0
            ),
        ]);

        $extractor = new FaceExtractor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid embedding size. Expected 2048 bytes (512d), got 1024');

        $extractor->extract('/tmp/short_embedding.jpg');
    }

    public function test_extract_throws_when_image_file_not_found(): void
    {
        // In unrecorded mode, missing image file throws fast
        $extractor = new FaceExtractor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Face extraction image file not found');

        $extractor->extract('/path/to/definitely/nonexistent_file_98765.jpg');
    }
}
