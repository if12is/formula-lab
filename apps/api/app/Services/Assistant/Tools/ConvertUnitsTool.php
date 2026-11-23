<?php

namespace App\Services\Assistant\Tools;

use App\Models\TradeMaterial;
use App\Services\Currency\ExchangeRateLookupService;
use App\Services\Material\MaterialDetailService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use App\Support\Currencies;
use App\Support\Engine\EngineRunner;

/**
 * Mass, volume, alcohol strength and money conversions by the engine. Mass to volume needs an explicit density (a material's sourced
 * density or one the person gave); money needs a dated exchange rate. Nothing is defaulted.
 */
final class ConvertUnitsTool implements AssistantTool
{
    use BuildsToolAnswers;

    private const DENSITY_UNITS = ['g/mL', 'kg/L'];

    public function __construct(
        private readonly EngineRunner $engine,
        private readonly MaterialDetailService $materials,
        private readonly ExchangeRateLookupService $rates,
    ) {}

    public function name(): string
    {
        return 'convert_units';
    }

    public function description(): string
    {
        return 'Convert a value between units: kg/g/mg, L/mL, %v/v to %w/w for ethanol (OIML R 22 table), mass to volume (needs materialId with a sourced density, or densityGml given by the user), and EGP to or from another currency (needs fxDate, YYYY-MM-DD). Units are written exactly: kg, g, mg, L, mL, %v/v, %w/w, EGP, USD.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string', 'description' => 'A decimal string.'],
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'materialId' => ['type' => 'string', 'description' => 'Its sourced density is used for mass and volume conversion.'],
                'densityGml' => ['type' => 'string', 'description' => 'A density in g/mL given by the user, when no material is named.'],
                'fxDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD, the day of the exchange rate for a currency conversion.'],
            ],
            'required' => ['value', 'from', 'to'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, [
            'value' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?$/'],
            'from' => ['required', 'string', 'max:12'],
            'to' => ['required', 'string', 'max:12'],
            'materialId' => ['nullable', 'uuid'],
            'densityGml' => ['nullable', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'fxDate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $request = ['value' => $valid['value'], 'from' => $valid['from'], 'to' => $valid['to']];
        $missing = [];

        if (isset($valid['materialId']) && ! TradeMaterial::query()->whereKey($valid['materialId'])->exists()) {
            return $this->missing([['materialId' => $valid['materialId'], 'property' => 'material', 'reason' => 'material.not_found']]);
        }

        $density = $this->density($valid['materialId'] ?? null, $valid['densityGml'] ?? null);
        if ($density !== null) {
            $request['density'] = $density;
        }

        $foreign = array_values(array_filter([$valid['from'], $valid['to']], fn (string $unit): bool => Currencies::isSupported($unit) && ! Currencies::isBase($unit)));
        if ($foreign !== []) {
            if (! isset($valid['fxDate'])) {
                $missing[] = ['property' => 'fxDate', 'reason' => 'input.missing'];
            } else {
                $rate = $this->rates->rateOn($foreign[0], $valid['fxDate']);
                if ($rate === null) {
                    $missing[] = ['property' => "fx:{$foreign[0]}->".Currencies::base(), 'reason' => 'fx.no_rate_on_or_before_date'];
                } else {
                    $request['fxRate'] = [
                        'from' => $foreign[0], 'to' => Currencies::base(), 'rate' => $rate['rate'], 'date' => $rate['rate_date'],
                        'sourceId' => $rate['source_id'], 'source' => $rate['source_title'], 'confidence' => $rate['confidence'],
                    ];
                }
            }
        }

        if ($missing !== []) {
            return $this->missing($missing);
        }

        $answer = $this->engine->run('assistant', ['op' => 'convert-units', 'input' => $request]);

        return ($answer['ok'] ?? false) === true
            ? $this->answer((array) $answer['value'], (array) ($answer['warnings'] ?? []))
            : $this->missing((array) ($answer['missing'] ?? []), [], (array) ($answer['warnings'] ?? []));
    }

    /** @return array{value: string, unit: string, sourceId: string, confidence: string}|null */
    private function density(?string $materialId, ?string $given): ?array
    {
        if ($given !== null) {
            // The person's own figure: no source behind it, so it is the weakest level.
            return ['value' => $given, 'unit' => 'g/mL', 'sourceId' => 'user_input', 'confidence' => 'L7_estimate'];
        }
        if ($materialId === null) {
            return null;
        }
        $material = TradeMaterial::query()->findOrFail($materialId);
        foreach ($this->materials->properties($material) as $value) {
            if ($value['property'] === 'density' && $value['value'] !== null && in_array($value['unit'], self::DENSITY_UNITS, true)) {
                // Newest first (the list is ordered by valid_from desc).
                return ['value' => $value['value'], 'unit' => $value['unit'], 'sourceId' => $value['source_id'], 'confidence' => $value['confidence']];
            }
        }

        return null;
    }
}
