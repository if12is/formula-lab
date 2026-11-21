<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'table_name' => 'substances',
            'record_id' => fake()->uuid(),
            'action' => AuditAction::Create,
            'before' => null,
            'after' => ['name_en' => 'Substance A'],
            'actor_id' => null,
            'reason' => null,
        ];
    }
}
