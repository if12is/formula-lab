<?php

namespace App\Services\Production;

use App\Enums\FieldGroup;
use App\Models\User;
use App\Services\Security\FieldVisibilityService;

/**
 * Who sees what in production. The role is read in ONE place (FieldVisibilityService::roleOf(), which U-01 replaces); the gate
 * `production.approve` (AppServiceProvider) asks allowsApproval(), so FormRequests and controllers keep working when real permissions land.
 *
 * What a role may see is decided by the central field policy (config/field_visibility.php, U-07): the operator (role `production`) gets
 * weights, order and lots, never a price, a cost or a percentage. With nobody signed in (no accounts yet) the full view is shown, as
 * everywhere else in the app; the weighing sheet itself is weights only for everybody.
 */
final class ProductionAudience
{
    public function __construct(private readonly FieldVisibilityService $visibility) {}

    public function roleOf(?User $user): ?string
    {
        return $this->visibility->roleOf($user);
    }

    /** The money of a production order or batch: what it costs (a sell price never appears on these screens; the price group is stripped by the middleware if it does). */
    public function seesPrices(?User $user): bool
    {
        return $this->visibility->allows($user, FieldGroup::Cost);
    }

    /** The percentage composition of the formula (the recipe): the operator weighs grams and never sees it. */
    public function seesPercentages(?User $user): bool
    {
        return $this->visibility->allows($user, FieldGroup::Percentage);
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
