<?php

namespace App\Services\Material;

use App\Actions\Material\CreateMaterialCompositionAction;
use App\Actions\Material\DeleteMaterialCompositionAction;
use App\Actions\Material\UpdateCompositionFlagsAction;
use App\Actions\Material\UpdateMaterialCompositionAction;
use App\Enums\Confidence;
use App\Models\MaterialComposition;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Collection;

/**
 * The single write path of material compositions, and their read side for the engine: `enginePayloads` turns the rows
 * into the engine's SourcedComposition (compliance/composition.ts), the one shape every screen hands to the engine.
 */
final class MaterialCompositionService
{
    public function __construct(
        private CreateMaterialCompositionAction $createComponent,
        private UpdateMaterialCompositionAction $updateComponent,
        private DeleteMaterialCompositionAction $deleteComponent,
        private UpdateCompositionFlagsAction $updateFlags,
    ) {}

    /** @param  array{cas?: string|null, inci?: string|null, pct_ww: string, source_id: string, confidence: string, valid_from: string}  $data */
    public function add(TradeMaterial $material, array $data, ?int $userId = null): MaterialComposition
    {
        return ($this->createComponent)($material, $this->clean($data), $userId);
    }

    /** @param  array{cas?: string|null, inci?: string|null, pct_ww: string, source_id: string, confidence: string, valid_from: string}  $data */
    public function update(MaterialComposition $component, array $data): MaterialComposition
    {
        return ($this->updateComponent)($component, $this->clean($data));
    }

    public function remove(MaterialComposition $component): void
    {
        ($this->deleteComponent)($component);
    }

    /** @param  array{composition_complete?: bool, is_fragrance?: bool}  $flags */
    public function setFlags(TradeMaterial $material, array $flags): TradeMaterial
    {
        return ($this->updateFlags)($material, $flags);
    }

    /**
     * The components of one material for its Composition tab.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(TradeMaterial $material): array
    {
        return $material->compositions()->with('source')->get()->map(fn (MaterialComposition $component): array => [
            'id' => $component->getKey(),
            'cas' => $component->cas,
            'inci' => $component->inci,
            'pct_ww' => DecimalNormalizer::normalize($component->pct_ww),
            'source_id' => $component->source_id,
            'source_title' => $component->source->title,
            'confidence' => $component->confidence->value,
            'valid_from' => $component->valid_from->toDateString(),
        ])->all();
    }

    /**
     * The engine's composition of each material, or null when the material has no component at all (its composition is
     * then unknown to the engine, which makes every concentration UNKNOWN: never a silent 0%).
     *
     * One composition has one source, confidence and date in the engine, so a material with components from several
     * sources is described by its weakest link: the worst confidence, that component's source, and the latest date.
     *
     * @param  Collection<int, TradeMaterial>  $materials
     * @return array<string, array{components: list<array<string, string>>, complete: bool, sourceId: string, confidence: string, validFrom: string}|null>
     */
    public function enginePayloads(Collection $materials): array
    {
        $rows = MaterialComposition::query()
            ->whereIn('trade_material_id', $materials->map(fn (TradeMaterial $material) => $material->getKey())->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('trade_material_id');
        $order = array_map(fn (Confidence $case): string => $case->value, Confidence::cases());

        return $materials->mapWithKeys(function (TradeMaterial $material) use ($rows, $order): array {
            /** @var Collection<int, MaterialComposition> $components */
            $components = $rows->get($material->getKey(), collect());

            if ($components->isEmpty()) {
                return [$material->getKey() => null];
            }

            $weakest = $components->sortByDesc(fn (MaterialComposition $component): int => (int) array_search($component->confidence->value, $order, true))->first();

            return [$material->getKey() => [
                'components' => $components->map(fn (MaterialComposition $component): array => array_filter([
                    'cas' => $component->cas,
                    'inci' => $component->inci,
                    'pctWW' => DecimalNormalizer::normalize($component->pct_ww),
                ], fn (?string $value): bool => $value !== null))->values()->all(),
                'complete' => (bool) $material->composition_complete,
                'sourceId' => $weakest->source_id,
                'confidence' => $weakest->confidence->value,
                'validFrom' => $components->max(fn (MaterialComposition $component): string => $component->valid_from->toDateString()),
            ]];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        foreach (['cas', 'inci'] as $key) {
            $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
            $data[$key] = $value === '' ? null : $value;
        }

        return $data;
    }
}
