<?php

namespace Tests\Feature\Coa;

use App\Support\Coa\PdftotextReader;
use Tests\TestCase;

/** P-05: the real text-layer reader (poppler's pdftotext) on the test-fixture certificates. */
class PdftotextReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path('tests/fixtures/coa/'.$name);
    }

    public function test_a_pdf_with_a_text_layer_gives_its_text(): void
    {
        $text = (new PdftotextReader)->read($this->fixture('sample-coa.pdf'), 'application/pdf');

        $this->assertNotNull($text);
        $this->assertStringContainsString('1.2610 g/mL', $text);
        $this->assertStringContainsString('DEMO-LOT-01', $text);
    }

    public function test_an_image_has_no_text_layer(): void
    {
        $this->assertNull((new PdftotextReader)->read($this->fixture('sample-coa-scan.png'), 'image/png'));
    }

    public function test_a_file_that_is_not_a_readable_pdf_gives_null(): void
    {
        $this->assertNull((new PdftotextReader)->read($this->fixture('sample-coa-scan.png'), 'application/pdf'));
        $this->assertNull((new PdftotextReader)->read($this->fixture('does-not-exist.pdf'), 'application/pdf'));
    }

    public function test_a_missing_binary_gives_null_not_an_error(): void
    {
        config(['coa.text_reader.binary' => 'no-such-pdftotext-binary']);

        $this->assertNull((new PdftotextReader)->read($this->fixture('sample-coa.pdf'), 'application/pdf'));
    }
}
