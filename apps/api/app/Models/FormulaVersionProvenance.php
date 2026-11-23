<?php

namespace App\Models;

use App\Enums\FormulaVersionOrigin;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\FormulaVersionProvenanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a generated version was made from (J-09): for an optimizer version the optimizer's inputs (profile, materials with quotes, rates,
 * balancing material), the tier profile record, the solver version and the input hash. Written once with the version; never changed.
 */
class FormulaVersionProvenance extends Model
{
    /** @use HasFactory<FormulaVersionProvenanceFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['formula_version_id', 'origin', 'tier_profile_id', 'tier', 'optimizer_inputs', 'optimizer_version', 'solver', 'input_hash', 'output_hash', 'cost_per_kg'];

    protected static function booted(): void
    {
        $guard = function (self $row): never {
            throw ImmutableRecordException::appendOnly($row->getTable());
        };
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origin' => FormulaVersionOrigin::class, 'optimizer_inputs' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<TierProfile, $this> */
    public function tierProfile(): BelongsTo
    {
        return $this->belongsTo(TierProfile::class);
    }
}
