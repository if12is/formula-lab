<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\PropertyValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A sourced property of a substance or trade material: a single value, or a min/max range. */
class PropertyValue extends Model
{
    /** @use HasFactory<PropertyValueFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'entity_type', 'entity_id', 'property', 'value', 'min', 'max', 'unit',
        'source_id', 'confidence', 'valid_from', 'lot_no', 'category', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => DecimalString::class,
            'min' => DecimalString::class,
            'max' => DecimalString::class,
            'confidence' => Confidence::class,
            'valid_from' => 'date:Y-m-d',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
