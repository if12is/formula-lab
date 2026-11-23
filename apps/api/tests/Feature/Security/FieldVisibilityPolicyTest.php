<?php

namespace Tests\Feature\Security;

use App\Enums\FieldGroup;
use App\Exceptions\Domain\FieldGroupForbiddenException;
use App\Models\User;
use App\Services\Security\FieldVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** U-07: which role receives which field group (config/field_visibility.php) and which screens are group-only. */
class FieldVisibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function groupsOf(?string $role): array
    {
        $user = $role === null ? null : User::factory()->create(['role' => $role]);

        return array_map(fn (FieldGroup $group): string => $group->value, app(FieldVisibilityService::class)->groupsFor($user));
    }

    public function test_the_four_roles_get_the_groups_the_owner_named_in_u01(): void
    {
        $this->assertSame(['cost', 'price', 'percentage'], $this->groupsOf('owner'));
        $this->assertSame(['cost', 'price', 'percentage'], $this->groupsOf('chemist'));
        $this->assertSame([], $this->groupsOf('production'), 'The operator sees weights only: no cost, no price, no percentage.');
        $this->assertSame(['cost'], $this->groupsOf('accountant'), 'The accountant sees cost only.');
    }

    public function test_nobody_signed_in_and_an_unlisted_role_keep_the_full_view_until_the_owner_switches_to_deny(): void
    {
        $this->assertSame(['cost', 'price', 'percentage'], $this->groupsOf(null));
        $this->assertSame(['cost', 'price', 'percentage'], $this->groupsOf('quality_manager'));

        config(['field_visibility.unlisted_role_groups' => [], 'field_visibility.anonymous_groups' => []]);
        $this->assertSame([], $this->groupsOf(null));
        $this->assertSame([], $this->groupsOf('quality_manager'));
    }

    public function test_the_role_list_is_config_so_the_owner_can_change_it_without_code(): void
    {
        config(['field_visibility.roles.production' => ['percentage']]);

        $this->assertSame(['percentage'], $this->groupsOf('production'));
    }

    public function test_a_group_only_screen_is_refused_for_a_role_without_that_group(): void
    {
        $service = app(FieldVisibilityService::class);
        $production = User::factory()->create(['role' => 'production']);
        $accountant = User::factory()->create(['role' => 'accountant']);

        $service->assertRouteAllowed($accountant, 'formulas.cost-report');
        $service->assertRouteAllowed($production, 'production.orders.show');
        $service->assertRouteAllowed($production, null);
        foreach (['formulas.cost-report', 'price-lists.index', 'price-ladder.index', 'tiers.index', 'formulas.versions.edit', 'formulas.publish'] as $route) {
            $this->assertThrowsException(FieldGroupForbiddenException::class, fn () => $service->assertRouteAllowed($production, $route));
        }
        $this->assertThrowsException(FieldGroupForbiddenException::class, fn () => $service->assertRouteAllowed($accountant, 'price-lists.show'));
        $this->assertThrowsException(FieldGroupForbiddenException::class, fn () => $service->assertRouteAllowed($accountant, 'formulas.versions.edit'));
    }

    public function test_the_refusal_is_worded_from_the_language_files_in_both_locales(): void
    {
        $production = User::factory()->create(['role' => 'production']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                app(FieldVisibilityService::class)->assertRouteAllowed($production, 'price-lists.index');
                $this->fail('Expected a refusal.');
            } catch (FieldGroupForbiddenException $exception) {
                $this->assertStringNotContainsString('exceptions.field_group_forbidden', $exception->getMessage());
                $this->assertStringContainsString(__('enums.field_group.price'), $exception->getMessage());
            }
        }
    }

    public function test_every_group_named_in_the_config_is_a_real_group_with_patterns(): void
    {
        foreach (['roles', 'keys', 'routes'] as $section) {
            foreach (array_keys((array) config("field_visibility.{$section}")) as $name) {
                if ($section !== 'roles') {
                    $this->assertNotNull(FieldGroup::tryFrom($name), "{$section}.{$name} is not a field group");
                }
            }
        }
        foreach (config('field_visibility.roles') as $role => $groups) {
            foreach ($groups as $group) {
                $this->assertNotNull(FieldGroup::tryFrom($group), "role {$role} names an unknown group {$group}");
            }
        }
        foreach (FieldGroup::cases() as $group) {
            $this->assertNotEmpty(config('field_visibility.keys.'.$group->value), "no key patterns for {$group->value}");
        }
    }
}
