<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Append-only (also enforced by a PostgreSQL trigger). */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = ['table_name', 'record_id', 'action', 'before', 'after', 'actor_id', 'reason'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('audit_log'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('audit_log'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'at' => 'datetime',
        ];
    }
}
