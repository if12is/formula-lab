<?php

namespace App\Models;

use App\Casts\EncryptedComposition;
use App\Concerns\Auditable;
use App\Concerns\HasEncryptedComposition;
use App\Enums\FormulaStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\Contracts\EncryptsComposition;
use App\Services\Crypto\TenantKeyring;
use App\Support\DecimalNormalizer;
use Database\Factories\FormulaLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient line of a version. What reveals the formula (which material, how much, at what temperature) is encrypted at rest
 * with the tenant's key (U-02); the line number, the q.s. flag and the phase stay plain (see plan/02-specs/composition-encryption.md).
 */
class FormulaLine extends Model implements EncryptsComposition
{
    /** @use HasFactory<FormulaLineFactory> */
    use Auditable, HasEncryptedComposition, HasFactory, HasUuids;

    private const ENCRYPTED_COLUMNS = ['trade_material_id', 'percent_ww', 'addition_temp_c'];

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
        return [
            'trade_material_id' => EncryptedComposition::class.':'.EncryptedComposition::UUID,
            'percent_ww' => EncryptedComposition::class.':'.EncryptedComposition::DECIMAL,
            'addition_temp_c' => EncryptedComposition::class.':'.EncryptedComposition::DECIMAL,
            'is_qs' => 'boolean',
            'line_no' => 'integer',
        ];
    }

    public function compositionTenantId(): string
    {
        return app(TenantKeyring::class)->tenantIdForVersion((string) ($this->getAttributes()['formula_version_id'] ?? ''));
    }

    /** @return list<string> */
    public function encryptedColumns(): array
    {
        return self::ENCRYPTED_COLUMNS;
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
