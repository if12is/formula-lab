<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Confidence;
use App\Enums\ProductTier;
use App\Enums\TaxMode;
use Database\Factories\FormulaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Formula extends Model
{
    /** @use HasFactory<FormulaFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'code', 'name', 'product_type', 'product_family_id', 'formula_template_id', 'tier', 'ifra_category', 'tax_mode', 'tax_rate_pct', 'sanad_product_uuid', 'unit_cost', 'unit_cost_source_id', 'unit_cost_confidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier' => ProductTier::class,
            'tax_mode' => TaxMode::class,
            'unit_cost' => DecimalString::class,
            'unit_cost_confidence' => Confidence::class,
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<ProductFamily, $this> The registry family of the product (K-01); null for a formula typed with a free product type. */
    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    /** @return BelongsTo<FormulaTemplate, $this> The template it was started from (K-09), if any. */
    public function formulaTemplate(): BelongsTo
    {
        return $this->belongsTo(FormulaTemplate::class);
    }

    /** @return HasMany<FormulaVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormulaVersion::class)->orderBy('version');
    }

    /** @return BelongsTo<Source, $this> Where the unit cost the price lists are checked against comes from. */
    public function unitCostSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'unit_cost_source_id');
    }
}
