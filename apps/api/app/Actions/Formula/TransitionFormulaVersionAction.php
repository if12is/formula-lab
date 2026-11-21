<?php

namespace App\Actions\Formula;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\FormulaWorkflowException;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One signed workflow move of a version: checks the move is allowed, re-checks the content hash of the lines, changes the
 * status and writes the signature (who, when, comment, hash) in the same transaction. Approved and rejected versions never
 * move again (the model guard and the database trigger refuse it too).
 */
final class TransitionFormulaVersionAction
{
    /**
     * @param  array<string, mixed>|null  $basis  What the decision rested on (the lab coverage), kept with the signature.
     *
     * @throws FormulaWorkflowException When the move is not allowed, a rejection has no reason, or the lines no longer match the hash.
     */
    public function __invoke(FormulaVersion $version, FormulaStatus $to, ?User $user = null, ?string $comment = null, ?array $basis = null): FormulaVersionTransition
    {
        return DB::transaction(function () use ($version, $to, $user, $comment, $basis): FormulaVersionTransition {
            $locked = FormulaVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canMoveTo($to)) {
                throw FormulaWorkflowException::transitionNotAllowed($from, $to);
            }

            $comment = $comment === null ? null : trim($comment);
            $comment = $comment === '' ? null : $comment;

            if ($to === FormulaStatus::Rejected && $comment === null) {
                throw FormulaWorkflowException::reasonRequired();
            }

            if (! $locked->verifyHash()) {
                throw FormulaWorkflowException::hashMismatch();
            }

            $locked->update(['status' => $to]);

            return FormulaVersionTransition::query()->create([
                'formula_version_id' => $locked->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'signed_by' => $user?->getKey(),
                'comment' => $comment,
                'version_hash' => $locked->hash,
                'basis' => $basis,
            ])->refresh();
        });
    }
}
