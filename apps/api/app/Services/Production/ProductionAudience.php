<?php

namespace App\Services\Production;

use App\Models\User;

/**
 * Who sees what in production. The role is read in ONE place, roleOf(), which U-01 (RBAC) replaces; the gate `production.approve`
 * (AppServiceProvider) asks allowsApproval(), so FormRequests and controllers keep working when real permissions land.
 *
 * A weights-only role (config production.weights_only_roles) is the operator: weights, order and lots, never a price or a cost.
 * With nobody signed in (no accounts yet) the full view is shown, as everywhere else in the app; the weighing sheet itself is
 * weights only for everybody.
 */
final class ProductionAudience
{
    public function roleOf(?User $user): ?string
    {
        $role = $user?->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    public function seesPrices(?User $user): bool
    {
        $role = $this->roleOf($user);

        return $role === null || ! in_array($role, (array) config('production.weights_only_roles'), true);
    }

    public function allowsApproval(?User $user): bool
    {
        $role = $this->roleOf($user);

        return $role !== null && in_array($role, (array) config('production.approver_roles'), true);
    }

    /**
     * What the screen says about an approval button: available, or the translation key of why not.
     *
     * @return array{available: bool, why: string|null}
     */
    public function approvalAvailability(?User $user): array
    {
        return match (true) {
            $user === null => ['available' => false, 'why' => 'production.approval.why_not_signed_in'],
            ! $this->allowsApproval($user) => ['available' => false, 'why' => 'production.approval.why_role'],
            default => ['available' => true, 'why' => null],
        };
    }
}
