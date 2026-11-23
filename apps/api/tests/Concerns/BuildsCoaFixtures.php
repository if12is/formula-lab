<?php

namespace Tests\Concerns;

use App\Models\CoaExtraction;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionService;
use App\Support\Coa\DocumentTextReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\fakes\anthropic\FakeMessagesClient;

/**
 * A scripted COA reading (P-05): a fake model (no network), a fake document-text reader and a small PDF upload.
 * The certificate text and its figures are test fixtures, not data of any real certificate.
 */
trait BuildsCoaFixtures
{
    protected FakeMessagesClient $model;

    /** The text the server "reads" from the uploaded PDF; null = a scan with no text layer. */
    protected ?string $documentText = "Certificate of Analysis\nProduct: Test Glycerin\nSupplier: Acme Chemicals\nLot: L240115\nDate: 2026-11-01\nDensity at 25 C: 1.2610 g/mL (ASTM D1298)\nPurity: 99.7 %w/w\nAcid value: 0.05 mgKOH/g\n";

    protected function setUpCoa(): void
    {
        Storage::fake((string) config('coa.disk'));
        $this->model = FakeMessagesClient::bind($this->app);
        $text = fn (): ?string => $this->documentText;
        $this->app->instance(DocumentTextReader::class, new class($text) implements DocumentTextReader
        {
            public function __construct(private readonly \Closure $text) {}

            public function read(string $absolutePath, string $mime): ?string
            {
                return ($this->text)();
            }
        });
    }

    protected function pdf(string $name = 'coa.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * One field of the model's `report_coa` answer; every key the schema requires is present.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reported(array $overrides = []): array
    {
        return [
            'propertyAsPrinted' => 'Density at 25 C',
            'propertyKey' => 'density',
            'value' => '1.2610',
            'min' => null,
            'max' => null,
            'unit' => 'g/mL',
            'method' => 'ASTM D1298',
            'quote' => 'Density at 25 C: 1.2610 g/mL (ASTM D1298)',
            'page' => 1,
            'readingConfidence' => 'high',
            ...$overrides,
        ];
    }

    /**
     * Queues the model's answer: the forced report_coa call.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $header
     */
    protected function modelReports(array $fields, array $header = []): void
    {
        $this->model->callTool('report_coa', ['supplier' => 'Acme Chemicals', 'lotNo' => 'L240115', 'documentDate' => '2026-11-01', ...$header, 'fields' => $fields]);
    }

    /** The three-field answer most tests start from: density, purity (a value) and acid value. */
    protected function modelReadsTheCertificate(): void
    {
        $this->modelReports([
            $this->reported(),
            $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => '99.7', 'unit' => '%w/w', 'method' => null, 'quote' => 'Purity: 99.7 %w/w']),
            $this->reported(['propertyAsPrinted' => 'Acid value', 'propertyKey' => 'acid_value', 'value' => '0.05', 'unit' => 'mgKOH/g', 'method' => null, 'quote' => 'Acid value: 0.05 mgKOH/g']),
        ]);
    }

    protected function extractCoa(TradeMaterial $material): CoaExtraction
    {
        $this->modelReadsTheCertificate();

        return app(CoaExtractionService::class)->extract($this->pdf(), $material);
    }
}
