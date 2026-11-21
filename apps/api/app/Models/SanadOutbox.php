<?php

namespace App\Models;

use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use Database\Factories\SanadOutboxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Work waiting for (or already acked by) Sanad. id is "ob_" + ULID: id order is creation order. */
class SanadOutbox extends Model
{
    /** @use HasFactory<SanadOutboxFactory> */
    use HasFactory;

    protected $table = 'sanad_outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'device_id', 'kind', 'external_id', 'payload', 'status', 'failures', 'retry_of',
        'result', 'last_error', 'created_at', 'acked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SanadOutboxKind::class,
            'status' => SanadOutboxStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'last_error' => 'array',
            'failures' => 'integer',
            'created_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SanadDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SanadDevice::class, 'device_id');
    }
}
