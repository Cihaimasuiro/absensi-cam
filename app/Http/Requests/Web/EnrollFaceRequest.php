<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class EnrollFaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');
        return $student && $student->hasActiveConsent();
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Siswa ini belum menyetujui pemrosesan data wajah (Consent). Silakan edit anggota dan centang persetujuan terlebih dahulu.');
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Foto wajib diunggah.',
            'photo.mimes' => 'Format foto harus JPG, PNG, atau WEBP.',
            'photo.max' => 'Ukuran maksimal foto adalah 5MB.',
        ];
    }
}
