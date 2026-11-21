<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\ComplianceRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One compliance rule, stored as the engine's rules DSL JSON (table `rules`). The model is not called Rule so it
 * never clashes with the validation Rule class. Rules of a published rule set cannot change.
 *
 * @property array<string, mixed> $rule
 */
class ComplianceRule extends Model
{
    /** @use HasFactory<ComplianceRuleFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $table = 'rules';

    protected $fillable = ['rule_set_id', 'rule', 'message', 'active', 'position'];

    protected static function booted(): void
    {
        $guard = function (self $rule): void {
            $set = RuleSet::query()->find($rule->rule_set_id);

            if ($set !== null && $set->isPublished()) {
                throw ImmutableRecordException::publishedRuleSet((string) $set->getKey());
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rule' => 'array', 'active' => 'boolean', 'position' => 'integer', 'pending_source' => 'boolean'];
    }

    /** @return BelongsTo<RuleSet, $this> */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }
}
