<?php

namespace App\Policies;

use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;

class StudentPolicy
{
    /**
     * Menentukan apakah pengguna boleh melihat daftar siswa.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    /**
     * Menentukan apakah pengguna boleh melihat data siswa tertentu.
     */
    public function view(User $user, Student $student): bool
    {
        return $user->can('student.view');
    }

    /**
     * Menentukan apakah pengguna boleh melihat foto biometrik wajah siswa.
     */
    public function viewPhoto(User $user, Student $student): bool
    {
        return $user->can('student.photo.view') || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'admin']));
    }

    /**
     * Menentukan apakah pengguna boleh menambah siswa.
     */
    public function create(User $user): bool
    {
        return $user->can('student.create');
    }

    /**
     * Menentukan apakah pengguna boleh memperbarui data siswa.
     */
    public function update(User $user, Student $student): bool
    {
        return $user->can('student.update');
    }

    /**
     * Menentukan apakah pengguna boleh menghapus siswa.
     */
    public function delete(User $user, Student $student): bool
    {
        return $user->can('student.delete');
    }

    /**
     * Menentukan apakah pengguna boleh mendaftarkan wajah siswa.
     */
    public function enroll(User $user, Student $student): bool
    {
        return $user->can('student.enroll');
    }

    /**
     * Menentukan apakah pengguna boleh melewati deteksi wajah duplikat.
     */
    public function bypassDuplicate(User $user): bool
    {
        return $user->can('enrollment.bypass-duplicate');
    }
}
