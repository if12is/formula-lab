<?php

namespace Tests\Feature\Http;

use App\Enums\TradeMaterialStatus;
use App\Http\Filters\ListQuery;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ListQueryTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $query */
    private function listRequest(array $query = []): Request
    {
        return Request::create('/materials', 'GET', $query);
    }

    /** @param  array<string, mixed>  $query */
    private function materialsList(array $query = []): ListQuery
    {
        return ListQuery::for(TradeMaterial::query(), $this->listRequest($query))
            ->search(['trade_name', 'grade', 'substance.name_en'])
            ->select('status', TradeMaterialStatus::class)
            ->multiselect('function', ['Surfactant', 'Solvent', 'Fragrance'])
            ->boolean('is_mixture')
            ->dateRange('created_at')
            ->sortable(['trade_name', 'status', 'created_at'], default: 'trade_name')
            ->perPage(5, [5, 10]);
    }

    /**
     * @param  array<string, mixed>  $list
     * @return list<string>
     */
    private function names(array $list): array
    {
        return array_column($list['rows'], 'trade_name');
    }

    protected function setUp(): void
    {
        parent::setUp();

        TradeMaterial::factory()->create(['trade_name' => 'Alpha Base', 'grade' => '70', 'function' => 'Surfactant', 'status' => TradeMaterialStatus::Active]);
        TradeMaterial::factory()->create(['trade_name' => 'Beta Base', 'grade' => '28', 'function' => 'Solvent', 'status' => TradeMaterialStatus::Draft]);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Gamma Blend', 'grade' => null, 'function' => 'Fragrance', 'status' => TradeMaterialStatus::Draft]);
    }

    public function test_it_returns_every_row_sorted_by_the_default_column_when_the_query_string_is_empty(): void
    {
        $list = $this->materialsList()->paginate();

        $this->assertSame(['Alpha Base', 'Beta Base', 'Gamma Blend'], $this->names($list));
        $this->assertSame(3, $list['meta']['total']);
        $this->assertSame('trade_name', $list['applied']['sort']);
        $this->assertSame('asc', $list['applied']['dir']);
    }

    public function test_it_searches_every_word_across_the_allowed_columns_case_insensitively(): void
    {
        $this->assertSame(['Alpha Base', 'Beta Base'], $this->names($this->materialsList(['search' => 'base'])->paginate()));
        $this->assertSame(['Alpha Base'], $this->names($this->materialsList(['search' => 'BASE 70'])->paginate()));
        $this->assertSame([], $this->materialsList(['search' => 'nothing-here'])->paginate()['rows']);
    }

    public function test_it_searches_through_a_relation_column(): void
    {
        $substance = Substance::factory()->create(['name_en' => 'Sodium Laureth']);
        TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Delta Grade']);

        $this->assertSame(['Delta Grade'], $this->names($this->materialsList(['search' => 'laureth'])->paginate()));
    }

    public function test_it_treats_like_wildcards_in_the_search_as_plain_characters(): void
    {
        TradeMaterial::factory()->create(['trade_name' => '100% Pure']);

        $this->assertSame(['100% Pure'], $this->names($this->materialsList(['search' => '%'])->paginate()));
        $this->assertSame([], $this->materialsList(['search' => '_'])->paginate()['rows']);
    }

    public function test_it_filters_by_a_select_value_and_ignores_a_value_outside_the_whitelist(): void
    {
        $this->assertSame(['Alpha Base'], $this->names($this->materialsList(['status' => 'active'])->paginate()));
        $this->assertCount(3, $this->names($this->materialsList(['status' => 'bogus'])->paginate()));
        $this->assertSame([], $this->materialsList(['status' => 'bogus'])->paginate()['applied']['filters']);
    }

    public function test_it_filters_by_several_values_written_comma_joined_dropping_unknown_ones(): void
    {
        $list = $this->materialsList(['function' => 'Surfactant,Fragrance,Hacker'])->paginate();

        $this->assertSame(['Alpha Base', 'Gamma Blend'], $this->names($list));
        $this->assertSame(['Surfactant', 'Fragrance'], $list['applied']['filters']['function']);
    }

    public function test_it_filters_by_a_boolean_flag_only_when_it_is_on(): void
    {
        $this->assertSame(['Gamma Blend'], $this->names($this->materialsList(['is_mixture' => '1'])->paginate()));
        $this->assertSame(3, $this->materialsList(['is_mixture' => '0'])->paginate()['meta']['total']);
    }

    public function test_it_filters_by_an_inclusive_date_range_and_ignores_malformed_dates(): void
    {
        TradeMaterial::query()->where('trade_name', 'Alpha Base')->update(['created_at' => '2026-01-10 08:00:00']);
        TradeMaterial::query()->where('trade_name', 'Beta Base')->update(['created_at' => '2026-02-10 08:00:00']);
        TradeMaterial::query()->where('trade_name', 'Gamma Blend')->update(['created_at' => '2026-03-10 08:00:00']);

        $this->assertSame(['Beta Base'], $this->names($this->materialsList(['created_at_from' => '2026-02-10', 'created_at_to' => '2026-02-10'])->paginate()));
        $this->assertSame(['Gamma Blend'], $this->names($this->materialsList(['created_at_from' => '2026-02-11'])->paginate()));
        $this->assertSame(3, $this->materialsList(['created_at_from' => '2026-13-45'])->paginate()['meta']['total']);
    }

    public function test_it_filters_by_an_exact_decimal_range_without_casting_to_float(): void
    {
        Substance::factory()->create(['name_en' => 'Light', 'mw' => '18.015']);
        Substance::factory()->create(['name_en' => 'Mid', 'mw' => '288.380']);
        Substance::factory()->create(['name_en' => 'Heavy', 'mw' => '1000.5']);

        $query = fn (array $params) => ListQuery::for(Substance::query(), $this->listRequest($params))
            ->numberRange('mw')
            ->sortable(['name_en'], default: 'name_en')
            ->paginate();

        $names = fn (array $list) => array_column($list['rows'], 'name_en');
        // The setUp trade materials brought substances with no mw; they never match a range.

        $this->assertSame(['Light', 'Mid'], $names($query(['mw_min' => '18.015', 'mw_max' => '288.38'])));
        $this->assertSame(['Heavy'], $names($query(['mw_min' => '288.381'])));
        $this->assertSame(Substance::count(), $query(['mw_min' => 'abc', 'mw_max' => '1e3'])['meta']['total']);
    }

    public function test_it_sorts_by_a_whitelisted_column_and_direction_falling_back_to_the_default_otherwise(): void
    {
        $this->assertSame(['Gamma Blend', 'Beta Base', 'Alpha Base'], $this->names($this->materialsList(['sort' => 'trade_name', 'dir' => 'desc'])->paginate()));
        $this->assertSame(['Gamma Blend', 'Beta Base', 'Alpha Base'], $this->names($this->materialsList(['sort' => 'password', 'dir' => 'desc'])->paginate()));
        $this->assertSame(['Alpha Base', 'Beta Base', 'Gamma Blend'], $this->names($this->materialsList(['sort' => 'password'])->paginate()));
    }

    public function test_it_uses_the_default_direction_when_only_the_sort_key_is_in_the_url(): void
    {
        $list = ListQuery::for(TradeMaterial::query(), $this->listRequest(['sort' => 'trade_name']))
            ->sortable(['trade_name', 'created_at'], default: 'created_at', direction: 'desc')
            ->paginate();

        $this->assertSame('trade_name', $list['applied']['sort']);
        $this->assertSame('desc', $list['applied']['dir']);
        $this->assertSame(['Gamma Blend', 'Beta Base', 'Alpha Base'], $this->names($list));
    }

    public function test_it_paginates_with_an_allowed_page_size_and_a_stable_order(): void
    {
        TradeMaterial::factory()->count(7)->sequence(fn ($s) => ['trade_name' => 'Zeta '.$s->index])->create();

        $first = $this->materialsList(['per' => '5'])->paginate();
        $second = $this->materialsList(['per' => '5', 'page' => '2'])->paginate();

        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 5, 'total' => 10], array_intersect_key($first['meta'], array_flip(['current_page', 'last_page', 'per_page', 'total'])));
        $this->assertSame(2, $second['meta']['current_page']);
        $this->assertSame([], array_intersect($this->names($first), $this->names($second)));
        $this->assertSame(5, $this->materialsList(['per' => '9999'])->paginate()['meta']['per_page']);
    }

    public function test_it_combines_search_filters_and_sort(): void
    {
        $list = $this->materialsList(['search' => 'base', 'status' => 'draft', 'sort' => 'trade_name', 'dir' => 'desc'])->paginate();

        $this->assertSame(['Beta Base'], $this->names($list));
    }

    public function test_it_describes_itself_for_the_frontend_with_enum_option_label_keys(): void
    {
        $schema = $this->materialsList()->schema();

        $this->assertSame(['placeholderKey' => 'filters.search_placeholder'], $schema['search']);
        $this->assertSame([
            'status' => 'select', 'function' => 'multiselect', 'is_mixture' => 'boolean', 'created_at' => 'date-range',
        ], array_column($schema['filters'], 'type', 'key'));
        $this->assertSame([
            ['value' => 'draft', 'labelKey' => 'enums.trade_material_status.draft'],
            ['value' => 'active', 'labelKey' => 'enums.trade_material_status.active'],
        ], $schema['filters'][0]['options']);
        $this->assertSame('filters.status', $schema['filters'][0]['labelKey']);
        $this->assertSame(['sort' => 'trade_name', 'dir' => 'asc', 'per' => 5], $schema['defaults']);
        $this->assertSame(['trade_name', 'status', 'created_at'], array_column($schema['sorts'], 'key'));
    }

    public function test_it_accepts_any_value_for_a_select_backed_by_a_remote_options_url(): void
    {
        $list = ListQuery::for(TradeMaterial::query(), $this->listRequest(['function' => 'Solvent']))
            ->select('function', optionsUrl: '/api/functions')
            ->sortable(['trade_name'], default: 'trade_name')
            ->paginate();

        $this->assertSame(['Beta Base'], $this->names($list));
        $this->assertSame('/api/functions', $list['schema']['filters'][0]['optionsUrl']);
    }

    public function test_it_maps_rows_through_the_transform_callback(): void
    {
        $list = $this->materialsList()->paginate(fn (TradeMaterial $material) => ['name' => $material->trade_name]);

        $this->assertSame(['name' => 'Alpha Base'], $list['rows'][0]);
    }
}
