<?php

namespace Database\Factories;

use App\Models\TermsAcceptance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TermsAcceptance> */
class TermsAcceptanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'session_key' => hash('sha256', fake()->unique()->uuid()),
            'terms_version' => config('terms.version'),
            'terms_hash' => hash('sha256', fake()->unique()->uuid()),
            'accepted_at' => now(),
        ];
    }
}
