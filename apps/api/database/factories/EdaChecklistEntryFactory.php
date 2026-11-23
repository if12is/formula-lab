<?php

namespace Database\Factories;

use App\Enums\EdaItemStatus;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EdaChecklistEntry> */
class EdaChecklistEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_id' => Formula::factory(),
            'item_code' => 'doc_a',
            'status' => EdaItemStatus::Attached,
            'document_title' => 'Test document',
        ];
    }

    public function notApplicable(): static
    {
        return $this->state(['status' => EdaItemStatus::NotApplicable, 'document_title' => null, 'note' => 'Not relevant to this product']);
    }
}
