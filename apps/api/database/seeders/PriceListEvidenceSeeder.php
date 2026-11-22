<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Enums\PriceListSyncStatus;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use App\Models\SanadCustomer;
use App\Models\SanadOutbox;
use App\Models\Source;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * One ready-made price list for the review screenshots and the e2e specs that need a list to start from: "قائمة العرض" with a
 * rule line (32.00 + 25 % = 40.00), an agreed price below cost (40.00 against a cost of 50.00) and a rule line the engine
 * cannot price (no unit cost, product not published), and two merchants. It replaces its own list each run. Needs the margin
 * rule "merchants" in config/pricing.php (the specs pass it as PRICING_RULES). Run with `php artisan db:seed --class=PriceListEvidenceSeeder`.
 */
class PriceListEvidenceSeeder extends Seeder
{
    public const LIST_NAME = 'قائمة العرض';

    public function run(): void
    {
        $this->call(PriceListDemoSeeder::class);

        $tenant = Tenant::query()->where('name', config('formula.default_tenant_name'))->firstOrFail();
        PriceList::query()->where('tenant_id', $tenant->id)->where('name', self::LIST_NAME)->get()->each(function (PriceList $list): void {
            SanadOutbox::query()->where('external_id', $list->id)->delete();
            $list->delete();
        });

        $list = PriceList::factory()->create(['tenant_id' => $tenant->id, 'name' => self::LIST_NAME, 'sync_status' => PriceListSyncStatus::Draft]);
        $formula = fn (string $code): Formula => Formula::query()->where('tenant_id', $tenant->id)->where('code', $code)->firstOrFail();

        PriceListItem::factory()->create(['price_list_id' => $list->id, 'formula_id' => $formula('PL-SHAMPOO')->id, 'mode' => PriceListMode::Rule, 'rule_key' => 'merchants']);
        PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'formula_id' => $formula('PL-SOAP')->id, 'mode' => PriceListMode::Override, 'rule_key' => null, 'override_price' => '40',
            'override_source_id' => Source::query()->where('title', 'Demo merchant agreement')->firstOrFail()->id, 'override_confidence' => Confidence::L2SupplierTds,
        ]);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'formula_id' => $formula('PL-FLOOR')->id, 'mode' => PriceListMode::Rule, 'rule_key' => 'merchants']);

        foreach (['c0de0000-0000-4000-8000-000000000001', 'c0de0000-0000-4000-8000-000000000002'] as $uuid) {
            PriceListCustomer::query()->where('customer_uuid', $uuid)->delete();
            PriceListCustomer::factory()->create(['price_list_id' => $list->id, 'customer_uuid' => SanadCustomer::query()->findOrFail($uuid)->uuid]);
        }
        SanadCustomer::query()->where('uuid', 'c0de0000-0000-4000-8000-000000000001')->update(['price_list_uuid' => $list->id]);
    }
}
