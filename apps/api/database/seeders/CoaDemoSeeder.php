<?php

namespace Database\Seeders;

use App\Enums\TradeMaterialStatus;
use App\Integrations\Anthropic\MessageResponse;
use App\Integrations\Anthropic\MessagesClient;
use App\Models\CoaExtraction;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * TEST-FIXTURE data for the COA review screen (P-05): a made-up material and two made-up certificates (tests/fixtures/coa) read through the real
 * pipeline (text layer, deterministic checks, records) by a scripted stand-in for the model, so the e2e run needs no network and no key.
 * The scripted answers include what the checks exist for: a number that is not on the document (pH 7.5), an ambiguous separator (1,412) and a value
 * the document does not give. Idempotent: a material that already has its extractions is left alone.
 * Not part of DatabaseSeeder; run with `php artisan db:seed --class=CoaDemoSeeder`.
 */
class CoaDemoSeeder extends Seeder
{
    /** The material whose two certificates stay open (waiting for a person): the review screen's evidence. */
    public const OPEN_MATERIAL = 'Demo Glycerin (COA)';

    /** The material whose certificates were decided: one confirmed with a correction and a rejection, one rejected. */
    public const DECIDED_MATERIAL = 'Demo Glycerin (COA decided)';

    public function run(): void
    {
        $this->readDemo(self::OPEN_MATERIAL);
        $this->decide($this->readDemo(self::DECIDED_MATERIAL));
    }

    /**
     * Reads the two fixture certificates (a PDF with a text layer, a scan without) for a material of this name.
     *
     * @return list<CoaExtraction> Empty when the material already has extractions: it is left alone.
     */
    public function readDemo(string $name): array
    {
        $material = TradeMaterial::query()->firstOrCreate(
            ['trade_name' => $name],
            ['grade' => '99.7', 'function' => 'Humectant', 'status' => TradeMaterialStatus::Active, 'substance_id' => null],
        );
        if (CoaExtraction::query()->where('trade_material_id', $material->getKey())->exists()) {
            return [];
        }

        $directory = base_path('tests/fixtures/coa');

        return [
            $this->readWith($material, $directory.'/sample-coa.pdf', 'application/pdf', 'sample-coa.pdf', 'DEMO-LOT-01', [
                $this->field('Density at 25 C', 'density', '1.2610', 'g/mL', 'ASTM D1298', 'Density at 25 C           ASTM D1298      1.2610 g/mL', 'high'),
                $this->field('Assay (purity)', 'purity', '99.7', '%w/w', 'Titration', 'Assay (purity)            Titration       99.7 %w/w', 'high'),
                $this->field('Acid value', 'acid_value', '0.05', 'mgKOH/g', 'Titration', 'Acid value                Titration       0.05 mgKOH/g', 'medium'),
                $this->field('Viscosity at 20 C', 'viscosity', '1,412', 'mPa.s', 'Brookfield', 'Viscosity at 20 C         Brookfield      1,412 mPa.s', 'medium'),
                $this->field('pH (10% solution)', 'ph', '7.5', null, null, null, 'low'),
                $this->field('Appearance', null, null, null, 'Visual', 'Appearance                Visual          Clear, colourless', 'high'),
            ]),
            $this->readWith($material, $directory.'/sample-coa-scan.png', 'image/png', 'sample-coa-scan.png', 'DEMO-LOT-02', [
                $this->field('Density at 25 C', 'density', '1.2610', 'g/mL', 'ASTM D1298', 'Density at 25 C           ASTM D1298      1.2610 g/mL', 'high'),
                $this->field('Assay (purity)', 'purity', '99.7', '%w/w', 'Titration', 'Assay (purity)            Titration       99.7 %w/w', 'high'),
            ]),
        ];
    }

    /**
     * Decides the extractions of a freshly read material the way a person would: the PDF is confirmed (density and purity accepted as read, acid value
     * with a typed correction, the rest rejected) and the scan is rejected.
     *
     * @param  list<CoaExtraction>  $extractions
     */
    private function decide(array $extractions): void
    {
        if (count($extractions) !== 2) {
            return;
        }
        [$pdf, $scan] = $extractions;
        $service = app(CoaExtractionService::class);

        $decisions = $pdf->fields->map(function ($field): array {
            $accept = in_array($field->property_key, ['density', 'purity', 'acid_value'], true);

            return [
                'id' => $field->getKey(),
                'decision' => $accept ? 'accept' : 'reject',
                'property' => $accept ? $field->property_key : null,
                'value' => $accept ? ($field->property_key === 'acid_value' ? '0.06' : $field->value) : null,
                'min' => null,
                'max' => null,
                'unit' => $accept ? $field->unit : null,
            ];
        })->all();
        $service->confirm($pdf, ['lot_no' => 'DEMO-LOT-01', 'valid_from' => '2026-11-01'], $decisions, null);
        $service->reject($scan, 'Test fixture: the reading does not match the original.', null);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function readWith(TradeMaterial $material, string $path, string $mime, string $name, string $lot, array $fields): CoaExtraction
    {
        $answer = ['supplier' => 'Demo Chemicals Ltd', 'lotNo' => $lot, 'documentDate' => '2026-11-01', 'fields' => $fields];

        app()->instance(MessagesClient::class, new class($answer) implements MessagesClient
        {
            /** @param  array<string, mixed>  $answer */
            public function __construct(private readonly array $answer) {}

            public function create(array $payload): MessageResponse
            {
                return new MessageResponse([['type' => 'tool_use', 'id' => 'toolu_demo', 'name' => 'report_coa', 'input' => $this->answer]], 'tool_use', 0, 0);
            }
        });

        return app(CoaExtractionService::class)->extract(new UploadedFile($path, $name, $mime, null, true), $material);
    }

    /** @return array<string, mixed> */
    private function field(string $printed, ?string $key, ?string $value, ?string $unit, ?string $method, ?string $quote, string $reading): array
    {
        return ['propertyAsPrinted' => $printed, 'propertyKey' => $key, 'value' => $value, 'min' => null, 'max' => null, 'unit' => $unit, 'method' => $method, 'quote' => $quote, 'page' => 1, 'readingConfidence' => $reading];
    }
}
