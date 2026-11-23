<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Assistant\AssistantRequestStatus;
use Database\Factories\AssistantRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The usage record of one question to the Claude Haiku assistant: token totals, status and the cost when prices are known. */
class AssistantRequest extends Model
{
    /** @use HasFactory<AssistantRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'model', 'status', 'api_calls', 'tool_calls', 'guard_retries',
        'input_tokens', 'output_tokens', 'cost', 'cost_currency', 'error_code', 'duration_ms',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => AssistantRequestStatus::class,
            'api_calls' => 'integer',
            'tool_calls' => 'integer',
            'guard_retries' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'cost' => DecimalString::class,
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
