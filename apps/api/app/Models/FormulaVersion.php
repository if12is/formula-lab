<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\FormulaStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Support\CanonicalJson;
use Database\Factories\FormulaVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One immutable-by-convention version of a formula; approved versions are immutable by guard. */
class FormulaVersion extends Model
{
    /** @use HasFactory<FormulaVersionFactory> */
    use Auditable, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['formula_id', 'version', 'hash', 'status', 'reason', 'parent_version_id', 'created_by'];

    protected static function booted(): void
    {
        $guard = function (self $version): void {
            if ($version->getRawOriginal('status') === FormulaStatus::Approved->value) {
                throw ImmutableRecordException::approvedFormulaVersion((string) $version->getKey());
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => FormulaStatus::class, 'version' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    /** @return HasMany<FormulaLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FormulaLine::class)->orderBy('line_no');
    }

    /** @return HasMany<FormulaProcessStep, $this> The manufacturing steps (Q-10): part of the version's content and hash. */
    public function processSteps(): HasMany
    {
        return $this->hasMany(FormulaProcessStep::class)->orderBy('step_no');
    }

    /** @return HasMany<LabTrial, $this> */
    public function trials(): HasMany
    {
        return $this->hasMany(LabTrial::class)->orderBy('created_at')->orderBy('code');
    }

    /** @return HasMany<FormulaVersionTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(FormulaVersionTransition::class)->orderBy('signed_at');
    }

    public function isApproved(): bool
    {
        return $this->status === FormulaStatus::Approved;
    }

    /**
     * Canonical lines (the hashed content) rebuilt from the formula_lines rows.
     * Single source of truth: nothing is duplicated into the version row except the hash.
     *
     * @return list<array<string, mixed>>
     */
    public function canonicalLines(): array
    {
        return $this->lines->map(fn (FormulaLine $line): array => $line->canonical())->all();
    }

    /**
     * What the hash covers: the lines alone for a version without process steps (so every hash made before Q-10 is unchanged),
     * the lines and the steps together for one that has them.
     *
     * @return list<array<string, mixed>>|array{lines: list<array<string, mixed>>, processSteps: list<array<string, mixed>>}
     */
    public function canonicalContent(): array
    {
        $this->unsetRelation('processSteps');
        $steps = $this->processSteps()->with(['checks', 'formulaLine'])->get();

        return self::content($this->canonicalLines(), $steps->map(fn (FormulaProcessStep $step): array => $step->canonical())->all());
    }

    /**
     * @param  list<array<string, mixed>>  $canonicalLines
     * @param  list<array<string, mixed>>  $canonicalSteps
     * @return list<array<string, mixed>>|array{lines: list<array<string, mixed>>, processSteps: list<array<string, mixed>>}
     */
    public static function content(array $canonicalLines, array $canonicalSteps = []): array
    {
        return $canonicalSteps === [] ? $canonicalLines : ['lines' => $canonicalLines, 'processSteps' => $canonicalSteps];
    }

    /** @param  list<array<string, mixed>>  $canonicalLines */
    public static function hashLines(array $canonicalLines): string
    {
        return CanonicalJson::hash($canonicalLines);
    }

    /** True when the stored lines (and steps) still hash to the recorded hash. */
    public function verifyHash(): bool
    {
        return hash_equals($this->hash, CanonicalJson::hash($this->canonicalContent()));
    }
}
