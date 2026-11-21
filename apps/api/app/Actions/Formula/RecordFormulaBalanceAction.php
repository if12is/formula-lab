<?php

namespace App\Actions\Formula;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\FormulaVersion;
use Illuminate\Support\Facades\Auth;

/** Writes the q.s. balancing events that led to a version into the audit trail (the history of "who balanced what"). */
final class RecordFormulaBalanceAction
{
    /**
     * @param  list<array{material: string, before: string|null, after: string}>  $events  Material names with the q.s. share before and after balancing.
     */
    public function __invoke(FormulaVersion $version, array $events, ?string $note): void
    {
        if ($events === []) {
            return;
        }

        AuditLog::query()->create([
            'table_name' => $version->getTable(),
            'record_id' => (string) $version->getKey(),
            'action' => AuditAction::Update,
            'before' => null,
            'after' => ['balance_events' => $events],
            'actor_id' => Auth::id(),
            'reason' => $note,
        ]);
    }
}
