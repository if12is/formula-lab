<?php

use App\Enums\TradeMaterialStatus;
use App\Http\Filters\ListQuery;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Http\Request;

function listRequest(array $query = []): Request
{
    return Request::create('/materials', 'GET', $query);
}

function materialsList(array $query = []): ListQuery
{
    return ListQuery::for(TradeMaterial::query(), listRequest($query))
        ->search(['trade_name', 'grade', 'substance.name_en'])
        ->select('status', TradeMaterialStatus::class)
        ->multiselect('function', ['Surfactant', 'Solvent', 'Fragrance'])
        ->boolean('is_mixture')
        ->dateRange('created_at')
        ->sortable(['trade_name', 'status', 'created_at'], default: 'trade_name')
        ->perPage(5, [5, 10]);
}

function names(array $list): array
{
    return array_column($list['rows'], 'trade_name');
}

beforeEach(function () {
    TradeMaterial::factory()->create(['trade_name' => 'Alpha Base', 'grade' => '70', 'function' => 'Surfactant', 'status' => TradeMaterialStatus::Active]);
    TradeMaterial::factory()->create(['trade_name' => 'Beta Base', 'grade' => '28', 'function' => 'Solvent', 'status' => TradeMaterialStatus::Draft]);
    TradeMaterial::factory()->mixture()->create(['trade_name' => 'Gamma Blend', 'grade' => null, 'function' => 'Fragrance', 'status' => TradeMaterialStatus::Draft]);
});

it('returns every row sorted by the default column when the query string is empty', function () {
    $list = materialsList()->paginate();

    expect(names($list))->toBe(['Alpha Base', 'Beta Base', 'Gamma Blend'])
        ->and($list['meta']['total'])->toBe(3)
        ->and($list['applied']['sort'])->toBe('trade_name')
        ->and($list['applied']['dir'])->toBe('asc');
});

it('searches every word across the allowed columns, case-insensitively', function () {
    expect(names(materialsList(['search' => 'base'])->paginate()))->toBe(['Alpha Base', 'Beta Base'])
        ->and(names(materialsList(['search' => 'BASE 70'])->paginate()))->toBe(['Alpha Base'])
        ->and(materialsList(['search' => 'nothing-here'])->paginate()['rows'])->toBe([]);
});

it('searches through a relation column', function () {
    $substance = Substance::factory()->create(['name_en' => 'Sodium Laureth']);
    TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Delta Grade']);

    expect(names(materialsList(['search' => 'laureth'])->paginate()))->toBe(['Delta Grade']);
});

it('treats LIKE wildcards in the search as plain characters', function () {
    TradeMaterial::factory()->create(['trade_name' => '100% Pure']);

    expect(names(materialsList(['search' => '%'])->paginate()))->toBe(['100% Pure'])
        ->and(materialsList(['search' => '_'])->paginate()['rows'])->toBe([]);
});

it('filters by a select value and ignores a value outside the whitelist', function () {
    expect(names(materialsList(['status' => 'active'])->paginate()))->toBe(['Alpha Base'])
        ->and(names(materialsList(['status' => 'bogus'])->paginate()))->toHaveCount(3)
        ->and(materialsList(['status' => 'bogus'])->paginate()['applied']['filters'])->toBe([]);
});

it('filters by several values written comma-joined, dropping unknown ones', function () {
    $list = materialsList(['function' => 'Surfactant,Fragrance,Hacker'])->paginate();

    expect(names($list))->toBe(['Alpha Base', 'Gamma Blend'])
        ->and($list['applied']['filters']['function'])->toBe(['Surfactant', 'Fragrance']);
});

it('filters by a boolean flag only when it is on', function () {
    expect(names(materialsList(['is_mixture' => '1'])->paginate()))->toBe(['Gamma Blend'])
        ->and(materialsList(['is_mixture' => '0'])->paginate()['meta']['total'])->toBe(3);
});

it('filters by an inclusive date range and ignores malformed dates', function () {
    TradeMaterial::query()->where('trade_name', 'Alpha Base')->update(['created_at' => '2026-01-10 08:00:00']);
    TradeMaterial::query()->where('trade_name', 'Beta Base')->update(['created_at' => '2026-02-10 08:00:00']);
    TradeMaterial::query()->where('trade_name', 'Gamma Blend')->update(['created_at' => '2026-03-10 08:00:00']);

    expect(names(materialsList(['created_at_from' => '2026-02-10', 'created_at_to' => '2026-02-10'])->paginate()))->toBe(['Beta Base'])
        ->and(names(materialsList(['created_at_from' => '2026-02-11'])->paginate()))->toBe(['Gamma Blend'])
        ->and(materialsList(['created_at_from' => '2026-13-45'])->paginate()['meta']['total'])->toBe(3);
});

it('filters by an exact decimal range without casting to float', function () {
    Substance::factory()->create(['name_en' => 'Light', 'mw' => '18.015']);
    Substance::factory()->create(['name_en' => 'Mid', 'mw' => '288.380']);
    Substance::factory()->create(['name_en' => 'Heavy', 'mw' => '1000.5']);

    $query = fn (array $params) => ListQuery::for(Substance::query(), listRequest($params))
        ->numberRange('mw')
        ->sortable(['name_en'], default: 'name_en')
        ->paginate();

    $names = fn (array $list) => array_column($list['rows'], 'name_en');
    // The beforeEach trade materials brought substances with no mw; they never match a range.

    expect($names($query(['mw_min' => '18.015', 'mw_max' => '288.38'])))->toBe(['Light', 'Mid'])
        ->and($names($query(['mw_min' => '288.381'])))->toBe(['Heavy'])
        ->and($query(['mw_min' => 'abc', 'mw_max' => '1e3'])['meta']['total'])->toBe(Substance::count());
});

it('sorts by a whitelisted column and direction, falling back to the default otherwise', function () {
    expect(names(materialsList(['sort' => 'trade_name', 'dir' => 'desc'])->paginate()))->toBe(['Gamma Blend', 'Beta Base', 'Alpha Base'])
        ->and(names(materialsList(['sort' => 'password', 'dir' => 'desc'])->paginate()))->toBe(['Gamma Blend', 'Beta Base', 'Alpha Base'])
        ->and(names(materialsList(['sort' => 'password'])->paginate()))->toBe(['Alpha Base', 'Beta Base', 'Gamma Blend']);
});

it('uses the default direction when only the sort key is in the URL', function () {
    $list = ListQuery::for(TradeMaterial::query(), listRequest(['sort' => 'trade_name']))
        ->sortable(['trade_name', 'created_at'], default: 'created_at', direction: 'desc')
        ->paginate();

    expect($list['applied']['sort'])->toBe('trade_name')->and($list['applied']['dir'])->toBe('desc')
        ->and(names($list))->toBe(['Gamma Blend', 'Beta Base', 'Alpha Base']);
});

it('paginates with an allowed page size and a stable order', function () {
    TradeMaterial::factory()->count(7)->sequence(fn ($s) => ['trade_name' => 'Zeta '.$s->index])->create();

    $first = materialsList(['per' => '5'])->paginate();
    $second = materialsList(['per' => '5', 'page' => '2'])->paginate();

    expect($first['meta'])->toMatchArray(['current_page' => 1, 'last_page' => 2, 'per_page' => 5, 'total' => 10])
        ->and($second['meta']['current_page'])->toBe(2)
        ->and(array_intersect(names($first), names($second)))->toBe([])
        ->and(materialsList(['per' => '9999'])->paginate()['meta']['per_page'])->toBe(5);
});

it('combines search, filters and sort', function () {
    $list = materialsList(['search' => 'base', 'status' => 'draft', 'sort' => 'trade_name', 'dir' => 'desc'])->paginate();

    expect(names($list))->toBe(['Beta Base']);
});

it('describes itself for the frontend with enum option label keys', function () {
    $schema = materialsList()->schema();

    expect($schema['search'])->toBe(['placeholderKey' => 'filters.search_placeholder'])
        ->and(array_column($schema['filters'], 'type', 'key'))->toBe([
            'status' => 'select', 'function' => 'multiselect', 'is_mixture' => 'boolean', 'created_at' => 'date-range',
        ])
        ->and($schema['filters'][0]['options'])->toBe([
            ['value' => 'draft', 'labelKey' => 'enums.trade_material_status.draft'],
            ['value' => 'active', 'labelKey' => 'enums.trade_material_status.active'],
        ])
        ->and($schema['filters'][0]['labelKey'])->toBe('filters.status')
        ->and($schema['defaults'])->toBe(['sort' => 'trade_name', 'dir' => 'asc', 'per' => 5])
        ->and(array_column($schema['sorts'], 'key'))->toBe(['trade_name', 'status', 'created_at']);
});

it('accepts any value for a select backed by a remote options url', function () {
    $list = ListQuery::for(TradeMaterial::query(), listRequest(['function' => 'Solvent']))
        ->select('function', optionsUrl: '/api/functions')
        ->sortable(['trade_name'], default: 'trade_name')
        ->paginate();

    expect(names($list))->toBe(['Beta Base'])
        ->and($list['schema']['filters'][0]['optionsUrl'])->toBe('/api/functions');
});

it('maps rows through the transform callback', function () {
    $list = materialsList()->paginate(fn (TradeMaterial $material) => ['name' => $material->trade_name]);

    expect($list['rows'][0])->toBe(['name' => 'Alpha Base']);
});
