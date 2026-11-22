<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\FormulaStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Support\DecimalNormalizer;
use Database\Factories\FormulaLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaLine extends Model
{
    /** @use HasFactory<FormulaLineFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['formula_version_id', 'line_no', 'trade_material_id', 'percent_ww', 'is_qs', 'phase', 'addition_temp_c'];

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $versionId = $line->getRawOriginal('formula_version_id') ?? $line->formula_version_id;
            $approved = FormulaVersion::query()->whereKey($versionId)
                ->where('status', FormulaStatus::Approved)->exists();
            if ($approved) {
                throw ImmutableRecordException::approvedFormulaVersion((string) $versionId);
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['percent_ww' => DecimalString::class, 'addition_temp_c' => DecimalString::class, 'is_qs' => 'boolean', 'line_no' => 'integer'];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function tradeMaterial(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class);
    }

    /**
     * The hashed form of this line: camelCase keys like the engine contract, decimals as normalized strings.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        $canonical = [
            'lineNo' => (string) $this->line_no,
            'tradeMaterialId' => $this->trade_material_id,
            'percentWw' => $this->percent_ww === null ? null : DecimalNormalizer::normalize($this->percent_ww),
            'isQs' => $this->is_qs,
            'phase' => $this->phase,
        ];

        // Only a line that has one carries a temperature, so the hash of every version saved before M-01 is unchanged.
        if ($this->addition_temp_c !== null) {
            $canonical['additionTempC'] = DecimalNormalizer::normalize($this->addition_temp_c);
        }

        return $canonical;
    }
}
