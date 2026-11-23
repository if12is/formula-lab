<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\EdaItemStatus;
use Database\Factories\EdaChecklistEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What was recorded for one item of a product's EDA notification file: the document attached, or why the item does not apply. */
class EdaChecklistEntry extends Model
{
    /** @use HasFactory<EdaChecklistEntryFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['formula_id', 'item_code', 'status', 'document_title', 'document_reference', 'file_path', 'file_name', 'note', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => EdaItemStatus::class];
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }
}
