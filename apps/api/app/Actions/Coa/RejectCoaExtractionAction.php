<?php

namespace App\Actions\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Exceptions\Domain\CoaException;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionEvent;
use Illuminate\Support\Facades\DB;

/** A person turns the whole extraction down: nothing is saved as a property value. The reason is kept with who and when. */
final class RejectCoaExtractionAction
{
    /** @throws CoaException When the extraction was already confirmed or rejected. */
    public function __invoke(CoaExtraction $extraction, string $reason, ?int $userId): CoaExtractionEvent
    {
        return DB::transaction(function () use ($extraction, $reason, $userId): CoaExtractionEvent {
            // Serialises two people deciding the same extraction; the unique index is the last line of defence.
            CoaExtraction::query()->whereKey($extraction->getKey())->lockForUpdate()->firstOrFail();

            $status = $extraction->status();
            if ($status->isFinal()) {
                throw CoaException::alreadyDecided($status->value);
            }

            return CoaExtractionEvent::query()->create([
                'coa_extraction_id' => $extraction->getKey(),
                'status' => CoaExtractionStatus::Rejected,
                'user_id' => $userId,
                'occurred_at' => now(),
                'reason' => $reason,
            ]);
        });
    }
}
