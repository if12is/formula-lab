<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ProductTier;
use Database\Factories\TierProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The constraints of one tier of one product type (J-01). `profile` is `{ targetRetailPrice: { per, value }, constraints: {...} }`,
 * every figure a decimal string or null; the optimizer validates it and the source (`source_id`) covers every figure written.
 */
class TierProfile extends Model
{
    /** @use HasFactory<TierProfileFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'product_type', 'tier', 'profile', 'source_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tier' => ProductTier::class, 'profile' => 'array'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
