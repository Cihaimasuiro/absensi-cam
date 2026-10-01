<?php

namespace App\Domain\Attendance\Services;

use Illuminate\Support\Facades\Http;
use Exception;

final class AIEngineService
{
    /**
     * Kirim frame gambar ke FastAPI untuk pendeteksian wajah (POST /detect)
     */
    public function detectFaces(string $imageBinary): array
    {
        $url = rtrim(config('services.ai_engine.url'), '/') . '/detect';
        $timeout = config('services.ai_engine.timeout', 30);
        $token = config('services.ai_engine.token');

        $request = Http::timeout($timeout);
        if (!empty($token)) {
            $request = $request->withHeaders(['X-AI-Engine-Token' => $token]);
        }

        $response = $request->attach('image', $imageBinary, 'frame.jpg')
            ->post($url);

        if ($response->failed()) {
            throw new Exception('AI Engine Detect Failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Kirim gambar & metadata ke FastAPI untuk pengenalan wajah (POST /face/recognize)
     */
    public function recognizeFace(string $imageBinary, array $metadata = []): array
    {
        $url = rtrim(config('services.ai_engine.url'), '/') . '/face/recognize';
        $timeout = config('services.ai_engine.timeout', 30);
        $token = config('services.ai_engine.token');

        $request = Http::timeout($timeout);
        if (!empty($token)) {
            $request = $request->withHeaders(['X-AI-Engine-Token' => $token]);
        }

        $defaultMeta = array_merge([
            'enable_liveness_detection' => true,
        ], $metadata);

        $response = $request->attach('image', $imageBinary, 'frame.jpg')
            ->post($url, [
                'metadata' => json_encode($defaultMeta),
            ]);

        if ($response->failed()) {
            throw new Exception('AI Engine Recognize Failed: ' . $response->body());
        }

        return $response->json();
    }
}
