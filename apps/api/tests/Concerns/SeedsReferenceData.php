<?php

namespace Tests\Concerns;

use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;

trait SeedsReferenceData
{
    protected function seedReferenceData(): void
    {
        $source = Source::factory()->create(['title' => 'Source A']);
        PropertyValue::factory()->count(3)->create(['source_id' => $source->id]);
        Price::factory()->create(['source_id' => $source->id]);
    }
}
