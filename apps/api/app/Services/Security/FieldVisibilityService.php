<?php

namespace App\Services\Security;

use App\Actions\Security\StripHiddenFieldsAction;
use App\Enums\FieldGroup;
use App\Exceptions\Domain\FieldGroupForbiddenException;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * U-07: the single policy that says which field groups a user receives from the server (config/field_visibility.php). The role is read
 * in ONE place, roleOf(), which U-01 (RBAC) replaces. Everything else asks this class: the middleware that strips the payloads and
 * guards group-only screens, the dashboard, the production screens (ProductionAudience) and the audience flags the pages read.
 */
final class FieldVisibilityService
{
    public function __construct(private readonly StripHiddenFieldsAction $strip) {}

    public function roleOf(?User $user): ?string
    {
        $role = $user?->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /** @return list<FieldGroup> */
    public function groupsFor(?User $user): array
    {
        $role = $this->roleOf($user);
        $names = match (true) {
            $user === null => config('field_visibility.anonymous_groups'),
            $role !== null && array_key_exists($role, (array) config('field_visibility.roles')) => config('field_visibility.roles.'.$role),
            default => config('field_visibility.unlisted_role_groups'),
        };

        return array_values(array_filter(array_map(fn (mixed $name): ?FieldGroup => FieldGroup::tryFrom((string) $name), (array) $names)));
    }

    public function allows(?User $user, FieldGroup $group): bool
    {
        return in_array($group, $this->groupsFor($user), true);
    }

    /** @return list<FieldGroup> The groups the user may NOT receive. */
    public function hiddenGroups(?User $user): array
    {
        return array_values(array_filter(FieldGroup::cases(), fn (FieldGroup $group): bool => ! $this->allows($user, $group)));
    }

    /**
     * What a screen is told about the audience, so it can leave out what it was not sent.
     *
     * @return array{sees_cost: bool, sees_price: bool, sees_percentage: bool}
     */
    public function audience(?User $user): array
    {
        return [
            'sees_cost' => $this->allows($user, FieldGroup::Cost),
            'sees_price' => $this->allows($user, FieldGroup::Price),
            'sees_percentage' => $this->allows($user, FieldGroup::Percentage),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function strip(array $payload, ?User $user): array
    {
        $denied = [];
        foreach ($this->hiddenGroups($user) as $group) {
            $denied = [...$denied, ...(array) config('field_visibility.keys.'.$group->value)];
        }

        return ($this->strip)($payload, $denied, (array) config('field_visibility.exempt_keys'));
    }

    /** @throws FieldGroupForbiddenException */
    public function assertRouteAllowed(?User $user, ?string $routeName): void
    {
        if ($routeName === null) {
            return;
        }
        foreach ($this->hiddenGroups($user) as $group) {
            foreach ((array) config('field_visibility.routes.'.$group->value) as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    throw FieldGroupForbiddenException::forGroup($group);
                }
            }
        }
    }
}
