<?php

namespace App\Services\Packaging;

use App\Models\PackagingCompatibility;
use App\Models\TradeMaterial;
use App\Support\Engine\EngineRunner;
use App\Support\Packaging\CompatPairsPayload;

/**
 * Asks the engine whether the closures and pumps among the given packaging components fit the container, from the pairs people
 * declared. The decision is the engine's (checkPackagingCompatibility); this service only loads the components and the pairs and
 * names the answer for the screen.
 */
final class PackagingCompatibilityCheckService
{
    public function __construct(private EngineRunner $engine) {}

    /**
     * @param  list<string>  $itemIds  Trade material ids of packaging components.
     * @return array{status: string, checks: list<array<string, mixed>>, missing: list<array<string, mixed>>, warnings: list<string>}
     */
    public function check(array $itemIds): array
    {
        $items = TradeMaterial::query()->packaging()->whereIn('id', $itemIds)->get()->keyBy(fn (TradeMaterial $item): string => (string) $item->getKey());
        $pairs = PackagingCompatibility::query()->with('source')->get();

        $answer = $this->engine->run('check-packaging-compat', [
            'items' => $items->map(fn (TradeMaterial $item): array => [
                'itemId' => (string) $item->getKey(),
                'kind' => $item->item_kind->value,
                'neckFinish' => $item->neck_finish,
            ])->values()->all(),
            'pairs' => CompatPairsPayload::load(),
        ]);

        $sourceTitles = $pairs->mapWithKeys(fn (PackagingCompatibility $pair): array => [(string) $pair->source_id => $pair->source?->title])->all();
        $describe = fn (string $id): array => ['id' => $id, 'name' => $items[$id]->trade_name, 'neck_finish' => $items[$id]->neck_finish];

        return [
            'status' => (string) $answer['status'],
            'checks' => array_map(fn (array $check): array => [
                'container' => $describe($check['containerId']),
                'closure' => $describe($check['closureId']),
                'status' => $check['status'],
                'source_title' => isset($check['pair']) ? ($sourceTitles[$check['pair']['sourceId']] ?? null) : null,
                'confidence' => $check['pair']['confidence'] ?? null,
            ], $answer['checks']),
            'missing' => array_map(fn (array $gap): array => [
                'item' => isset($gap['materialId']) && isset($items[$gap['materialId']]) ? $items[$gap['materialId']]->trade_name : null,
                'property' => $gap['property'],
                'reason' => $gap['reason'],
            ], $answer['missing']),
            'warnings' => $answer['warnings'],
        ];
    }
}
