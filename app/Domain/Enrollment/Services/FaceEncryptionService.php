<?php

namespace App\Domain\Enrollment\Services;

class FaceEncryptionService
{
    /**
     * Encrypts the raw face embedding using AES-256-GCM.
     *
     * @param string $rawEmbedding The raw binary embedding (2048 bytes for ArcFace-512)
     * @param string $studentId The student ID for Additional Authenticated Data (AAD)
     * @param string $modelVersion The model version for AAD
     * @return string The encrypted blob (nonce + ciphertext + tag)
     *
     * @throws \RuntimeException If encryption fails or key is invalid
     */
    public function encrypt(string $rawEmbedding, string $studentId, string $modelVersion): string
    {
        $keyBase64 = config('app.enrollment_embed_key');
        if (!$keyBase64) {
            throw new \RuntimeException('ENROLLMENT_EMBED_KEY is not set');
        }
        
        $key = base64_decode($keyBase64);
        if (strlen($key) !== 32) {
            throw new \RuntimeException('ENROLLMENT_EMBED_KEY must be exactly 32 bytes when decoded');
        }

        $nonce = random_bytes(12);
        $aad = $studentId . '_' . $modelVersion;
        $tag = '';
        
        $ciphertext = openssl_encrypt(
            $rawEmbedding, 
            'aes-256-gcm', 
            $key, 
            OPENSSL_RAW_DATA, 
            $nonce, 
            $tag, 
            $aad, 
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("AES encryption failed");
        }

        return $nonce . $ciphertext . $tag;
    }
}
