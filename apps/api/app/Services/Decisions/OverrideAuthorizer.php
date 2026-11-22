<?php

namespace App\Services\Decisions;

use App\Models\User;

/**
 * Who may override a blocked decision (Y-04): a signed-in user whose role is in config('decisions.override_roles'). The role is
 * read in ONE place, roleOf(), which U-01 (RBAC) replaces. Registered as the `decisions.override` gate in AppServiceProvider, so
 * FormRequests ask `$user->can('decisions.override')` and keep working when login and real permissions land.
 */
final class OverrideAuthorizer
{
    public function roleOf(User $user): ?string
    {
        $role = $user->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    public function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $role = $this->roleOf($user);

        return $role !== null && in_array($role, (array) config('decisions.override_roles'), true);
    }

    /**
     * What the screen says about the override button: available, or the translation key of why not.
     *
     * @return array{available: bool, why: string|null}
     */
    public function availability(?User $user): array
    {
        return match (true) {
            $user === null => ['available' => false, 'why' => 'decision_ui.override.why_not_signed_in'],
            ! $this->allows($user) => ['available' => false, 'why' => 'decision_ui.override.why_role'],
            default => ['available' => true, 'why' => null],
        };
    }
}
