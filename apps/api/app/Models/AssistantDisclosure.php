<?php

namespace App\Models;

use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\AssistantDisclosureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** What categories of data went (or were refused to go) to the model provider, through which surface. Never the content itself. Append-only. */
class AssistantDisclosure extends Model
{
    /** @use HasFactory<AssistantDisclosureFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['session_key', 'surface', 'categories', 'outcome', 'user_id', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('assistant_disclosures'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('assistant_disclosures'));
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'outcome' => AssistantDisclosureOutcome::class,
            'categories' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
