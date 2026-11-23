<?php

namespace Tests\Concerns;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaVersion;
use App\Models\SanadDevice;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaProcessService;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * An approved product made of three materials (TEST-FIXTURE numbers, hand-computable): surfactant 12 %, salt 1.5 %, water q.s. (86.5 %).
 * A 2.5 kg batch is 300 g / 37.5 g / 2162.5 g; at a 1 g balance resolution the halves round to even (38 g and 2162 g). The version is
 * hashed by the real version service (so its hash verifies), then approved. Its steps add the water first, then the surfactant, then the salt.
 */
trait BuildsProductionFixtures
{
    protected Tenant $tenant;

    protected Formula $formula;

    protected FormulaVersion $version;

    protected TradeMaterial $surfactant;

    protected TradeMaterial $salt;

    protected TradeMaterial $water;

    protected SanadDevice $device;

    /** @return list<array<string, mixed>> */
    protected function productionSteps(): array
    {
        return [
            ['kind' => 'add', 'phase' => null, 'line_no' => '3', 'temperature_c' => '40', 'mixing_speed_rpm' => '60', 'duration_minutes' => '10', 'note' => null, 'checks' => []],
            ['kind' => 'add', 'line_no' => '1', 'temperature_c' => null, 'mixing_speed_rpm' => null, 'duration_minutes' => '15', 'checks' => [['property' => 'ph', 'unit' => 'pH', 'limit_min' => null, 'limit_max' => null]]],
            ['kind' => 'add', 'line_no' => '2', 'checks' => []],
        ];
    }

    /** An approved, linked, costed product; pass false to leave something out. */
    protected function productionProduct(bool $linkToSanad = true, bool $approveCost = true, bool $withSteps = true): FormulaVersion
    {
        $this->tenant = Tenant::factory()->create();
        $source = Source::factory()->create(['title' => 'Cost sheet 2026-11']);
        $this->formula = Formula::factory()->create([
            'tenant_id' => $this->tenant->id,
            'unit_cost' => '18.4573',
            'unit_cost_source_id' => $source->id,
            'unit_cost_confidence' => Confidence::L2SupplierTds,
            'sanad_product_uuid' => $linkToSanad ? (string) Str::uuid() : null,
        ]);

        $make = fn (string $name): TradeMaterial => TradeMaterial::factory()->create([
            'trade_name' => $name,
            'substance_id' => null,
            'sanad_product_uuid' => $linkToSanad ? (string) Str::uuid() : null,
        ]);
        $this->surfactant = $make('Surfactant X');
        $this->salt = $make('Salt');
        $this->water = $make('Water');

        $this->version = app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => $this->surfactant->id, 'percent_ww' => '12'],
            ['trade_material_id' => $this->salt->id, 'percent_ww' => '1.5'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);

        if ($withSteps) {
            app(FormulaProcessService::class)->replace($this->version, $this->productionSteps());
        }
        $this->version->refresh()->update(['status' => FormulaStatus::Approved]);

        $this->device = SanadDevice::factory()->for($this->tenant)->create();
        if ($approveCost) {
            FormulaCostApproval::factory()->create([
                'formula_id' => $this->formula->id,
                'formula_version_id' => $this->version->id,
                'unit_cost' => '18.4573',
                'unit_cost_source_id' => $source->id,
                'unit_cost_confidence' => Confidence::L2SupplierTds,
                'approved_at' => now(),
            ]);
        }

        return $this->version->refresh();
    }

    /** @return array<string, mixed> */
    protected function orderData(array $overrides = []): array
    {
        return [
            'batch_size' => '2.5',
            'batch_unit' => 'kg',
            'scale_resolution_g' => '1',
            'units_count' => '500',
            'planned_on' => '2026-11-25',
            'expiry_date' => '2028-11-24',
            'packaging' => [],
            ...$overrides,
        ];
    }

    /**
     * Asserts the database itself refuses the statement (a trigger or a constraint). It runs in a savepoint, so the refused statement does
     * not poison the transaction the test runs in.
     */
    protected function assertDatabaseRefuses(callable $statement, ?string $messageContains = null): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction($statement), $messageContains);
    }

    /**
     * The props the page was sent (the translation dictionary left out, so a word in it is never mistaken for data).
     *
     * @return array<string, mixed>
     */
    protected function pageProps(TestResponse $response): array
    {
        $props = $response->original->getData()['page']['props'];
        unset($props['translations']);

        return $props;
    }
}
