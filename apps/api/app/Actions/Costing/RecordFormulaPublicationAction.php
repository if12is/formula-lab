<?php

namespace App\Actions\Costing;

use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use App\Support\DTOs\SanadPublishResult;

/** Records one push of a finished good to Sanad: the details sent, the decision that allowed it and where it went. */
final class RecordFormulaPublicationAction
{
    /**
     * @param  array<string, mixed>  $details  What was sent (cost price from the approval, selling price, SKU, ...).
     * @param  array<string, mixed>  $record  The engine's hashed decision record (the overriding one when $overrideId is set).
     */
    public function __invoke(Formula $formula, FormulaCostApproval $approval, SanadPublishResult $result, array $details, array $record, ?int $userId, ?string $overrideId = null): FormulaPublication
    {
        return FormulaPublication::query()->create([
            'tenant_id' => $formula->tenant_id,
            'formula_id' => $formula->getKey(),
            'cost_approval_id' => $approval->getKey(),
            'sanad_product_uuid' => $result->productUuid,
            'mode' => $result->mode,
            'outbox_id' => $result->outboxId,
            'details' => $details,
            'decision' => $record,
            'decision_hash' => $record['decisionHash'],
            'decision_override_id' => $overrideId,
            'published_by' => $userId,
        ]);
    }
}
