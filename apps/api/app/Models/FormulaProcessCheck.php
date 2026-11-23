<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use App\Support\DecimalNormalizer;
use Database\Factories\FormulaProcessCheckFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A check point of a process step: the property to read and its limits. A limit names its source, or it is null (not evaluated). */
class FormulaProcessCheck extends Model
{
    /** @use HasFactory<FormulaProcessCheckFactory> */
    use Auditable, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['formula_process_step_id', 'check_no', 'property', 'unit', 'limit_min', 'limit_max', 'source_id', 'confidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'check_no' => 'integer',
            'limit_min' => DecimalString::class,
            'limit_max' => DecimalString::class,
            'confidence' => Confidence::class,
        ];
    }

    /** @return BelongsTo<FormulaProcessStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(FormulaProcessStep::class, 'formula_process_step_id');
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        $canonical = ['checkNo' => (string) $this->check_no, 'property' => $this->property, 'unit' => $this->unit];
        if ($this->limit_min !== null) {
            $canonical['limitMin'] = DecimalNormalizer::normalize($this->limit_min);
        }
        if ($this->limit_max !== null) {
            $canonical['limitMax'] = DecimalNormalizer::normalize($this->limit_max);
        }
        if ($this->source_id !== null) {
            $canonical['sourceId'] = $this->source_id;
            $canonical['confidence'] = $this->confidence?->value;
        }

        return $canonical;
    }
}
