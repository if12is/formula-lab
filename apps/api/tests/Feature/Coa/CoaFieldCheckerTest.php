<?php

namespace Tests\Feature\Coa;

use App\Support\Assistant\NumberGuard;
use App\Support\Coa\CoaDocumentText;
use App\Support\Coa\CoaFieldChecker;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\TestCase;

/** P-05: the unit checks use the engine's exact table (a Node process runs for real); the property registry is extended in the test only. */
class CoaFieldCheckerTest extends TestCase
{
    use BuildsCoaFixtures;

    private function check(array $raw, ?string $text): array
    {
        return app(CoaFieldChecker::class)->check($raw, CoaDocumentText::of($text, app(NumberGuard::class)));
    }

    public function test_a_unit_the_engine_can_convert_exactly_is_converted_and_the_printed_one_is_kept(): void
    {
        config(['properties.test_mass' => ['units' => ['kg'], 'label_key' => 'properties.density']]);

        $field = $this->check($this->reported(['propertyKey' => 'test_mass', 'value' => '1500', 'unit' => 'g', 'quote' => null]), 'Net weight 1500 g');

        $this->assertSame('1.5', $field['value']);
        $this->assertSame('kg', $field['unit']);
        $this->assertSame('g', $field['converted_from']);
        $this->assertSame('g', $field['as_printed']['unit']);
        $this->assertContains('converted', $field['issues']);
    }

    public function test_a_range_is_converted_end_by_end(): void
    {
        config(['properties.test_mass' => ['units' => ['kg'], 'label_key' => 'properties.density']]);

        $field = $this->check($this->reported(['propertyKey' => 'test_mass', 'value' => null, 'min' => '500', 'max' => '1500', 'unit' => 'g', 'quote' => null]), '500 - 1500 g');

        $this->assertSame(['0.5', '1.5'], [$field['min'], $field['max']]);
        $this->assertNull($field['value']);
    }

    public function test_a_unit_of_another_dimension_is_never_converted(): void
    {
        config(['properties.test_mass' => ['units' => ['kg'], 'label_key' => 'properties.density']]);

        $field = $this->check($this->reported(['propertyKey' => 'test_mass', 'value' => '2', 'unit' => 'mL', 'quote' => null]), 'Volume 2 mL');

        $this->assertNull($field['unit']);
        $this->assertContains('unit_not_allowed', $field['issues']);
    }

    public function test_a_unit_the_engine_does_not_know_is_not_accepted(): void
    {
        $field = $this->check($this->reported(['unit' => 'furlong', 'quote' => null]), 'Density 1.2610 furlong');

        $this->assertNull($field['unit']);
        $this->assertContains('unit_not_allowed', $field['issues']);
    }

    public function test_a_missing_unit_on_a_property_with_units_is_not_filled_in(): void
    {
        $field = $this->check($this->reported(['unit' => null, 'quote' => null]), 'Density 1.2610');

        $this->assertNull($field['unit']);
        $this->assertContains('unit_not_allowed', $field['issues']);
    }

    public function test_a_number_is_found_whatever_the_words_around_it(): void
    {
        // Dates, codes and glued letters would be masked as "not numbers" by the reply guard; in a document they are still where a figure can come from.
        $text = 'Test D1298 at 25C gave 0.915g/mL, lot 2026-11-01';

        $this->assertSame('0.915', $this->check($this->reported(['value' => '0.915', 'quote' => null]), $text)['value']);
        $this->assertNull($this->check($this->reported(['value' => '0.916', 'quote' => null]), $text)['value']);
    }
}
