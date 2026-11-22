<?php

namespace App\Models;

use App\Enums\Confidence;
use App\Enums\TemplateVerification;
use Database\Factories\FormulaTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A formula template (K-09): lines by role with a percentage only where a source gave one, and how far it has been proven. */
class FormulaTemplate extends Model
{
    /** @use HasFactory<FormulaTemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['code', 'product_family_id', 'name_ar', 'name_en', 'verification_status', 'approved_by', 'approved_on', 'source_id', 'confidence', 'lines', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verification_status' => TemplateVerification::class,
            'approved_on' => 'date:Y-m-d',
            'confidence' => Confidence::class,
            'lines' => 'array',
            'position' => 'integer',
        ];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' ? $this->name_en : $this->name_ar;
    }

    /** @return BelongsTo<ProductFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** How many lines still lack a share a person must type: not q.s., not optional, and no percentage in the template. */
    public function missingPercentCount(): int
    {
        return count(array_filter($this->lines, fn (array $line): bool => ! $line['qs'] && $line['percentWW'] === null));
    }
}
