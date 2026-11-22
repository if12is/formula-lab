<?php

namespace App\Actions\Family;

use App\Exceptions\Domain\EngineException;
use App\Exceptions\Domain\InvalidFamilyDataException;
use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use App\Models\Source;
use App\Support\Engine\EngineRunner;
use App\Support\Families\FamilyData;
use Illuminate\Support\Facades\DB;

/**
 * Loads the shipped registry and template files into the database. The engine validates both files first, so a vocabulary
 * reference that does not resolve, a guessed value or a status that claims more than the data proves is refused before
 * anything is written. Idempotent: rows are matched by code and rewritten from the files, which stay the source of truth.
 */
final class SyncProductFamiliesAction
{
    public function __construct(private EngineRunner $engine, private FamilyData $data) {}

    /**
     * @return array{families: int, templates: int}
     *
     * @throws InvalidFamilyDataException When the engine rejects the files.
     * @throws EngineException When the engine cannot answer.
     */
    public function __invoke(): array
    {
        $registry = $this->data->registry();
        $templates = $this->data->templates();

        $answer = $this->engine->run('validate-families', ['registry' => $registry, 'templates' => ['templates' => $templates]]);

        if (($answer['ok'] ?? false) !== true) {
            throw InvalidFamilyDataException::rejectedByEngine($answer['missing'] ?? []);
        }

        return DB::transaction(function () use ($registry, $templates): array {
            $sources = [];
            foreach ($registry['sources'] as $source) {
                $sources[$source['id']] = Source::query()->firstOrCreate(
                    ['kind' => config('families.source_kind'), 'title' => $source['title'], 'tenant_id' => null],
                    ['retrieved_at' => $source['retrievedAt']],
                )->getKey();
            }

            $familyIds = [];
            foreach ($registry['families'] as $position => $family) {
                $familyIds[$family['code']] = ProductFamily::query()->updateOrCreate(['code' => $family['code']], [
                    'group' => $family['group'],
                    'name_ar' => $family['name']['ar'],
                    'name_en' => $family['name']['en'],
                    'regulator' => $family['regulator'],
                    'ifra_category' => $family['ifraCategory'],
                    'required_calculations' => $family['requiredCalculations'],
                    'measured_properties' => $family['measuredProperties'],
                    'tax_regime' => $family['taxRegime'],
                    'notes_key' => $family['notes'] ?? null,
                    'source_id' => $sources[$family['sourceId']],
                    'confidence' => $family['confidence'],
                    'verification' => $family['verification'],
                    'position' => $position,
                ])->getKey();
            }

            foreach ($templates as $position => $template) {
                FormulaTemplate::query()->updateOrCreate(['code' => $template['code']], [
                    'product_family_id' => $familyIds[$template['familyCode']],
                    'name_ar' => $template['name']['ar'],
                    'name_en' => $template['name']['en'],
                    'verification_status' => $template['verification'],
                    'approved_by' => $template['approvedBy'] ?? null,
                    'approved_on' => $template['approvedOn'] ?? null,
                    'source_id' => $sources[$template['sourceId']],
                    'confidence' => $template['confidence'],
                    'lines' => $template['lines'],
                    'position' => $position,
                ]);
            }

            return ['families' => count($registry['families']), 'templates' => count($templates)];
        });
    }
}
