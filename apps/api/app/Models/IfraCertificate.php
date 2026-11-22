<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\IfraCertificateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The IFRA certificate of a fragrance oil: the document its category limits (property `ifra_limit`) are read from. */
class IfraCertificate extends Model
{
    /** @use HasFactory<IfraCertificateFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'trade_material_id', 'certificate_number', 'ifra_amendment', 'issuer', 'issued_on', 'source_id', 'confidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'confidence' => Confidence::class];
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function tradeMaterial(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasMany<PropertyValue, $this> The category limits read from this certificate. */
    public function limits(): HasMany
    {
        return $this->hasMany(PropertyValue::class, 'ifra_certificate_id')->orderBy('category');
    }
}
