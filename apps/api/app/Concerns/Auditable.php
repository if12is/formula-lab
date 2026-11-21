<?php

namespace App\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records who/when/before/after of every create, update and delete in audit_log (ADR 003).
 * `when` is the database server clock. Set a reason for the next write with withAuditReason().
 *
 * @mixin Model
 */
trait Auditable
{
    private ?string $pendingAuditReason = null;

    public static function bootAuditable(): void
    {
        static::created(fn (self $model) => $model->writeAudit(AuditAction::Create, null, $model->getAttributes()));

        static::updated(function (self $model): void {
            $changed = array_keys($model->getChanges());
            $changed = array_values(array_diff($changed, [$model->getUpdatedAtColumn()]));
            if ($changed === []) {
                return;
            }
            $model->writeAudit(
                AuditAction::Update,
                array_intersect_key($model->getRawOriginal(), array_flip($changed)),
                array_intersect_key($model->getAttributes(), array_flip($changed)),
            );
        });

        static::deleted(fn (self $model) => $model->writeAudit(AuditAction::Delete, $model->getRawOriginal(), null));
    }

    /** Attach a justification to the next audited write of this instance. */
    public function withAuditReason(string $reason): static
    {
        $this->pendingAuditReason = $reason;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function writeAudit(AuditAction $action, ?array $before, ?array $after): void
    {
        AuditLog::query()->create([
            'table_name' => $this->getTable(),
            'record_id' => (string) $this->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'actor_id' => Auth::id(),
            'reason' => $this->pendingAuditReason,
        ]);

        $this->pendingAuditReason = null;
    }
}
