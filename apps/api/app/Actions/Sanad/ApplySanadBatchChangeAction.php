<?php

namespace App\Actions\Sanad;

use App\Actions\Sanad\Concerns\ReadsSanadChange;
use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadRefType;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\RequestFingerprint;
use Illuminate\Support\Str;

/** Records a Sanad batch as a reference only (COA lots link to it later); batches are never written by Formula Lab. */
final class ApplySanadBatchChangeAction
{
    use ReadsSanadChange;

    public function __construct(private readonly UpsertSanadRefAction $upsertRef) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function __invoke(string $tenantId, array $item): SanadChangeOutcome
    {
        $isDelete = $this->isDelete($item);

        if (! $this->hasValidUuid($item) || (! $isDelete && $this->payloadNamesAnotherUuid($item))) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }
        if (! $isDelete && $this->payloadIsInvalid(SanadEntity::Batch, $item)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::SchemaMismatch);
        }

        return ($this->upsertRef)(
            $tenantId,
            SanadRefType::Batch,
            Str::lower((string) $item['uuid']),
            $this->updatedAt($item),
            RequestFingerprint::hash((array) $item['payload']),
            $isDelete,
        );
    }
}
