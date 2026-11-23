<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\PackagingCompatibilityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A declared pair (H-02): this container neck finish takes a closure or pump of that finish. Entered by a person, with its source. */
class PackagingCompatibility extends Model
{
    /** @use HasFactory<PackagingCompatibilityFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'container_neck_finish', 'closure_neck_finish', 'source_id', 'confidence', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confidence' => Confidence::class];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
