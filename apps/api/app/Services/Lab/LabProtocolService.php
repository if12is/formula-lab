<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Http\Filters\ListQuery;
use App\Models\LabProtocol;
use Illuminate\Http\Request;

/** Read side of the protocol templates (O-06): a filtered list and one protocol with its steps. */
final class LabProtocolService
{
    /** @return array<string, mixed> */
    public function list(Request $request): array
    {
        return ListQuery::for(LabProtocol::query(), $request)
            ->search(['code'], 'lab.protocols_page.search_placeholder')
            ->select('property', LabProperty::class, labelKey: 'lab.protocols_page.filters.property')
            ->sortable(['position', 'property'], default: 'position', labelPrefix: 'lab.protocols_page.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (LabProtocol $protocol): array => [
                'id' => $protocol->getKey(),
                'code' => $protocol->code,
                'name' => __("lab.protocols.{$protocol->code}.name"),
                'property' => $protocol->property->value,
                'unit' => $protocol->unit,
                'instrument' => __("lab.instruments.{$protocol->instrument}"),
                'steps' => count($protocol->steps),
                'href' => route('lab.protocols.show', $protocol, false),
            ]);
    }

    /** @return array<string, mixed> */
    public function forProtocol(LabProtocol $protocol): array
    {
        return [
            'protocol' => [
                'id' => $protocol->getKey(),
                'code' => $protocol->code,
                'name' => __("lab.protocols.{$protocol->code}.name"),
                'description' => __("lab.protocols.{$protocol->code}.description"),
                'property' => $protocol->property->value,
                'unit' => $protocol->unit,
                'instrument' => __("lab.instruments.{$protocol->instrument}"),
                'steps' => array_map(fn (string $step): string => __("lab.protocols.{$protocol->code}.steps.{$step}"), $protocol->steps),
                'limits' => $protocol->property->limits(),
            ],
            'urls' => ['index' => route('lab.protocols.index', [], false)],
        ];
    }
}
