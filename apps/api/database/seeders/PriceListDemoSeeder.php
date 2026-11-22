<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\Sanad\SanadAbility;
use App\Models\Formula;
use App\Models\SanadCustomer;
use App\Models\SanadDevice;
use App\Models\Source;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo data for the price list screens: three products (two published to Sanad with a sourced unit cost, one not published and
 * with no cost), a few Sanad customers in the read-only cache and a linked Sanad device. The Playwright specs and the review
 * screenshots use them. The figures are placeholders for the screens, not business data: unit costs 32.00 and 50.00, so a +25 %
 * rule prices the first at 40.00. It never creates a margin rule (those are the owner's, config/pricing.php) or a price list.
 * Idempotent. Run with `php artisan db:seed --class=PriceListDemoSeeder`.
 */
class PriceListDemoSeeder extends Seeder
{
    public const DEVICE_NAME = 'Demo Sanad';

    private const LOCK_KEY = 'price-list-demo-seeder';

    /** @var array<string, array{name: string, name_en: string|null, wholesale: bool, active: bool, list: string|null}> */
    private const CUSTOMERS = [
        'c0de0000-0000-4000-8000-000000000001' => ['name' => 'تاجر الجملة - القاهرة', 'name_en' => 'Cairo Wholesaler', 'wholesale' => true, 'active' => true, 'list' => null],
        'c0de0000-0000-4000-8000-000000000002' => ['name' => 'سوبر ماركت النور', 'name_en' => null, 'wholesale' => false, 'active' => true, 'list' => null],
        'c0de0000-0000-4000-8000-000000000003' => ['name' => 'مخازن الإسكندرية', 'name_en' => 'Alexandria Stores', 'wholesale' => true, 'active' => true, 'list' => 'a11e0000-0000-4000-8000-000000000001'],
        'c0de0000-0000-4000-8000-000000000004' => ['name' => 'صيدلية الأمل', 'name_en' => null, 'wholesale' => false, 'active' => false, 'list' => null],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $this->seed();
        });
    }

    private function seed(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
        $cost = Source::query()->firstOrCreate(['title' => 'Demo cost sheet'], ['kind' => 'owner_decision', 'retrieved_at' => '2026-11-20']);
        Source::query()->firstOrCreate(['title' => 'Demo merchant agreement'], ['kind' => 'owner_decision', 'retrieved_at' => '2026-11-21']);

        $this->formula($tenant, 'PL-SHAMPOO', 'شامبو فاخر 400 مل', '32.00', $cost, published: true);
        $this->formula($tenant, 'PL-SOAP', 'صابون سائل 500 مل', '50.00', $cost, published: true);
        $this->formula($tenant, 'PL-FLOOR', 'منظف أرضيات 1 لتر', null, null, published: false);

        foreach (self::CUSTOMERS as $uuid => $row) {
            SanadCustomer::query()->updateOrCreate(['uuid' => $uuid], [
                'tenant_id' => $tenant->id, 'code' => 'CLT-'.substr($uuid, -4), 'name' => $row['name'], 'name_en' => $row['name_en'],
                'is_active' => $row['active'], 'is_wholesale' => $row['wholesale'], 'price_list_uuid' => $row['list'],
                'updated_at' => '2026-11-21 09:30:00+00', 'deleted' => false, 'synced_at' => now(),
            ]);
        }

        SanadDevice::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => self::DEVICE_NAME], [
            'token_hash' => SanadDevice::hashToken(Str::random(40)), 'abilities' => SanadAbility::values(),
        ]);
    }

    private function formula(Tenant $tenant, string $code, string $name, ?string $unitCost, ?Source $source, bool $published): void
    {
        $formula = Formula::query()->firstOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'product_type' => 'demo']);

        $formula->forceFill([
            'name' => $name,
            'sanad_product_uuid' => $published ? ($formula->sanad_product_uuid ?? (string) Str::uuid()) : null,
            'unit_cost' => $unitCost,
            'unit_cost_source_id' => $unitCost === null ? null : $source?->id,
            'unit_cost_confidence' => $unitCost === null ? null : Confidence::L3Standard,
        ])->save();
    }
}
