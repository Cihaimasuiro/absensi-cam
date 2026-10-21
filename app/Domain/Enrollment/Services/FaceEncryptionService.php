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
    private function getDecodedKey(): string
    {
        $keyBase64 = config('app.enrollment_embed_key');
        if (empty($keyBase64)) {
            throw new \RuntimeException('ENROLLMENT_EMBED_KEY is not set');
        }

        if (str_starts_with($keyBase64, 'base64:')) {
            $keyBase64 = substr($keyBase64, 7);
        }

        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('ENROLLMENT_EMBED_KEY must be exactly 32 bytes when decoded');
        }

        return $key;
    }

    public function encrypt(string $rawEmbedding, string $studentId, string $modelVersion): string
    {
        $key = $this->getDecodedKey();

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

    /**
     * Decrypts the encrypted blob back to raw binary embedding (2048 bytes).
     *
     * @param string $encryptedBlob The encrypted blob (12-byte nonce + ciphertext + 16-byte tag)
     * @param string $studentId The student ID for Additional Authenticated Data (AAD)
     * @param string $modelVersion The model version for AAD
     * @return string The raw binary embedding (2048 bytes)
     *
     * @throws \RuntimeException If decryption fails or authentication tag does not match
     */
    public function decrypt(string $encryptedBlob, string $studentId, string $modelVersion): string
    {
        $key = $this->getDecodedKey();

        if (strlen($encryptedBlob) < 28) {
            throw new \RuntimeException('Encrypted blob is too short');
        }

        $nonce = substr($encryptedBlob, 0, 12);
        $tag = substr($encryptedBlob, -16);
        $ciphertext = substr($encryptedBlob, 12, -16);
        $aad = $studentId . '_' . $modelVersion;

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new \RuntimeException("AES-GCM decryption failed for student {$studentId}");
        }

        return $plaintext;
    }
}
