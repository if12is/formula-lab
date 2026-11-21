<?php

namespace App\Services\Calculator;

use JsonException;

/**
 * The soap property maps shipped with the engine (packages/engine/data/soap-property-maps/*.json), offered to the saponification
 * calculator as selectable sources. A map is a published convention (which fatty acids count toward hardness, cleansing...),
 * data with its own source and confidence; this service only reads it. Nothing is added or changed here.
 */
final class SoapPropertyMapService
{
    /**
     * Every map, with what the engine takes (`map`) and what the screen shows.
     *
     * @return list<array{value: string, label: string, description: string, sourceTitle: string, confidence: string, pendingVerification: bool, notMapped: list<string>, acids: list<string>, map: array<string, mixed>}>
     */
    public function options(): array
    {
        $options = [];

        foreach (glob(config('calculators.soap_property_maps_path').'/*.json') ?: [] as $file) {
            $document = $this->read($file);
            $id = (string) $document['id'];
            $acids = collect($document['properties'])->flatMap(fn (array $property): array => array_column($property['terms'], 'acid'))->unique()->values()->all();

            $options[] = [
                'value' => $id,
                'label' => __("calculators.soap_properties.maps.{$id}.title"),
                'description' => __("calculators.soap_properties.maps.{$id}.description"),
                'sourceTitle' => (string) $document['sourceTitle'],
                'confidence' => (string) $document['confidence'],
                'pendingVerification' => (bool) ($document['pendingVerification'] ?? false),
                'notMapped' => array_values($document['notMapped'] ?? []),
                'acids' => $acids,
                // Exactly the shape of the engine's PropertyMap.
                'map' => ['sourceId' => $document['sourceId'], 'confidence' => $document['confidence'], 'properties' => $document['properties']],
            ];
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

        return $options;
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        try {
            $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $document = null;
        }

        if (! is_array($document) || ! isset($document['id'], $document['sourceId'], $document['sourceTitle'], $document['confidence'], $document['properties'])) {
            throw new \RuntimeException("Not a soap property map: {$file}");
        }

        return $document;
    }
}
