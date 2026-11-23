<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Production\ProcessStepKind;
use App\Support\DecimalNormalizer;
use Database\Factories\FormulaProcessStepFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One manufacturing step of a formula version (Q-10). Part of the version's hash; immutable once the version is approved (trigger). */
class FormulaProcessStep extends Model
{
    /** @use HasFactory<FormulaProcessStepFactory> */
    use Auditable, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['formula_version_id', 'step_no', 'kind', 'phase', 'formula_line_id', 'temperature_c', 'mixing_speed_rpm', 'duration_minutes', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ProcessStepKind::class,
            'step_no' => 'integer',
            'temperature_c' => DecimalString::class,
            'mixing_speed_rpm' => DecimalString::class,
            'duration_minutes' => DecimalString::class,
        ];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<FormulaLine, $this> The ingredient an `add` step puts in. */
    public function formulaLine(): BelongsTo
    {
        return $this->belongsTo(FormulaLine::class);
    }

    /** @return HasMany<FormulaProcessCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(FormulaProcessCheck::class)->orderBy('check_no');
    }

    /**
     * The hashed form: the ingredient is named by its line number (a uuid differs between copies of the same content), and
     * only the values that are present are written, so a step without a number hashes the same whatever the column order.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        $canonical = ['stepNo' => (string) $this->step_no, 'kind' => $this->kind->value];
        foreach (['phase' => 'phase', 'note' => 'note'] as $key => $attribute) {
            if ($this->{$attribute} !== null && $this->{$attribute} !== '') {
                $canonical[$key] = $this->{$attribute};
            }
        }
        if ($this->formula_line_id !== null) {
            $canonical['lineNo'] = (string) $this->formulaLine->line_no;
        }
        foreach (['temperatureC' => 'temperature_c', 'mixingSpeedRpm' => 'mixing_speed_rpm', 'durationMinutes' => 'duration_minutes'] as $key => $attribute) {
            if ($this->{$attribute} !== null) {
                $canonical[$key] = DecimalNormalizer::normalize($this->{$attribute});
            }
        }
        if ($this->checks->isNotEmpty()) {
            $canonical['checks'] = $this->checks->map(fn (FormulaProcessCheck $check): array => $check->canonical())->all();
        }

        return $canonical;
    }
}
