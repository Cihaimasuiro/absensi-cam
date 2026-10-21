<?php

namespace App\Policies;

use App\Domain\Student\Models\Student;
use App\Domain\User\Models\User;

class StudentPolicy
{
    /**
     * Determine whether the user can view any students.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('student.view');
    }

    /**
     * Determine whether the user can view a specific student.
     */
    public function view(User $user, Student $student): bool
    {
        return $user->can('student.view');
    }

    /**
     * Determine whether the user can view the student's biometric face photo.
     */
    public function viewPhoto(User $user, Student $student): bool
    {
        return $user->can('student.photo.view') || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'admin']));
    }

    /**
     * Determine whether the user can create students.
     */
    public function create(User $user): bool
    {
        return $user->can('student.create');
    }

    /**
     * Determine whether the user can update the student.
     */
    public function update(User $user, Student $student): bool
    {
        return $user->can('student.update');
    }

    /**
     * Determine whether the user can delete the student.
     */
    public function delete(User $user, Student $student): bool
    {
        return $user->can('student.delete');
    }

    /**
     * Determine whether the user can enroll the student's face.
     */
    public function enroll(User $user, Student $student): bool
    {
        return $user->can('student.enroll');
    }

    /**
     * Determine whether the user can bypass duplicate face detection.
     */
    public function bypassDuplicate(User $user): bool
    {
        return $user->can('enrollment.bypass-duplicate');
    }
}
