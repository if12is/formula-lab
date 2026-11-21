<?php

namespace App\Models;

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

    protected $fillable = ['tenant_id', 'code', 'name', 'product_type', 'tier', 'ifra_category', 'tax_mode', 'tax_rate_pct', 'sanad_product_uuid'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tier' => ProductTier::class, 'tax_mode' => TaxMode::class];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<FormulaVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormulaVersion::class)->orderBy('version');
    }
}
