<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/**
 * Where something Formula Lab asked Sanad to do stands, in one word for every screen: the finished good publish, a production order's
 * stock effect (consume the materials and packaging, receive the finished good). Sanad owns the stock; this only says what it answered.
 */
enum SanadDeliveryStatus: string
{
    use HasOptions;

    /** Nothing was queued or sent yet. */
    case NotSent = 'not_sent';
    /** In the outbox, waiting for Sanad to pull it. */
    case Queued = 'queued';
    /** Sanad refused an earlier try; the item was queued again under a new id. */
    case Retrying = 'retrying';
    /** Sanad answered that it applied the message (no stock detail to confirm). */
    case Applied = 'applied';
    /** Sanad answered that it consumed AND received: the stock effect is done in Sanad. */
    case Confirmed = 'confirmed';
    /** Sanad consumed but did not receive the finished good: a person must look. */
    case Partial = 'partial';
    /** Refused by Sanad (LAN mode: the same order can be sent again). */
    case Failed = 'failed';
    /** Refused the allowed number of times: a person must look. */
    case NeedsReview = 'needs_review';
    /** Sanad did not apply it because of a local change there: a person decides. */
    case Conflict = 'conflict';

    /** The states in which a person has to act (shown as an alert). */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Partial, self::Failed, self::NeedsReview, self::Conflict], true);
    }
}
