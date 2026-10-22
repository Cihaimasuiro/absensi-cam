<?php

namespace App\Domain\Enrollment\Services;

class FaceEncryptionService
{
    /**
     * Mengambil lalu men-decode base64 kunci AES-256-GCM 32 byte, sekaligus membuang
     * awalan 'base64:' jika ada.
     *
     * @throws \RuntimeException Jika kunci tidak ada atau panjangnya bukan 32 byte
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

    /**
     * Enkripsi embedding wajah mentah memakai AES-256-GCM.
     *
     * @param string $rawEmbedding Embedding biner mentah (2048 byte untuk ArcFace-512)
     * @param string $studentId ID siswa untuk Additional Authenticated Data (AAD)
     * @param string $modelVersion Versi model untuk AAD
     * @return string Blob terenkripsi (nonce + ciphertext + tag)
     *
     * @throws \RuntimeException Jika enkripsi gagal atau kunci tidak valid
     */
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
     * Dekripsi blob terenkripsi kembali menjadi embedding biner mentah (2048 byte).
     *
     * @param string $encryptedBlob Blob terenkripsi (nonce 12 byte + ciphertext + tag 16 byte)
     * @param string $studentId ID siswa untuk Additional Authenticated Data (AAD)
     * @param string $modelVersion Versi model untuk AAD
     * @return string Embedding biner mentah (2048 byte)
     *
     * @throws \RuntimeException Jika dekripsi gagal atau authentication tag tidak cocok
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
