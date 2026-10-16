<?php

namespace App\Domain\Enrollment\Exceptions;

use RuntimeException;

class DuplicateFaceException extends RuntimeException
{
    public function __construct(
        public readonly string $matchedStudentId,
        public readonly float $similarity,
        string $message = 'Wajah ini terdeteksi sangat mirip dengan anggota lain yang sudah terdaftar. Hubungi administrator jika ini adalah saudara kembar.'
    ) {
        parent::__construct($message);
    }
}
