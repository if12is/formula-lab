<?php

namespace App\Models;

use App\Enums\Sanad\SanadAbility;
use Database\Factories\SanadDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Sanad install linked to one tenant. The bearer token itself is never stored, only its sha256. */
class SanadDevice extends Model
{
    /** @use HasFactory<SanadDeviceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'token_hash', 'abilities', 'sanad_device_uuid',
        'last_seen_at', 'push_cursor', 'pull_cursor',
    ];

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'push_cursor' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hasAbility(SanadAbility $ability): bool
    {
        return in_array($ability->value, (array) $this->abilities, true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<SanadOutbox, $this> */
    public function outbox(): HasMany
    {
        return $this->hasMany(SanadOutbox::class, 'device_id');
    }
}
