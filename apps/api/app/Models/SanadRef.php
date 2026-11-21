<?php

namespace App\Models;

use App\Enums\Sanad\SanadRefType;
use Database\Factories\SanadRefFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Id mapping between a Sanad uuid and a Formula Lab row (no commerce data lives here). */
class SanadRef extends Model
{
    /** @use HasFactory<SanadRefFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'entity_type', 'sanad_uuid', 'local_table', 'local_id',
        'last_synced_at', 'payload_hash', 'sanad_updated_at', 'deleted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entity_type' => SanadRefType::class,
            'last_synced_at' => 'datetime',
            'sanad_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
