<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\RuleSetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A versioned set of compliance rules. A published rule set is immutable (the model and a database trigger both refuse a change). */
class RuleSet extends Model
{
    /** @use HasFactory<RuleSetFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'code', 'version', 'name', 'scope_notes', 'status', 'hash', 'source_id', 'published_at', 'created_by'];

    protected static function booted(): void
    {
        $guard = function (self $ruleSet): void {
            if ($ruleSet->getRawOriginal('status') === RuleSetStatus::Published->value) {
                throw ImmutableRecordException::publishedRuleSet((string) $ruleSet->getKey());
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => RuleSetStatus::class, 'published_at' => 'datetime'];
    }

    /** @return HasMany<ComplianceRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ComplianceRule::class)->orderBy('position');
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function isPublished(): bool
    {
        return $this->status === RuleSetStatus::Published;
    }
}
