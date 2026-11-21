<?php

namespace App\Http\Filters;

use App\Enums\FilterType;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one code path every index controller uses to turn the request's query string
 * (search, filters, sort, page) into an Eloquent query, from a whitelist.
 *
 *   $list = ListQuery::for(TradeMaterial::query(), $request)
 *       ->search(['trade_name', 'grade'])
 *       ->select('status', TradeMaterialStatus::class)
 *       ->boolean('is_mixture')
 *       ->dateRange('created_at')
 *       ->sortable(['trade_name', 'created_at'], default: 'trade_name')
 *       ->paginate(fn (TradeMaterial $m) => [...]);
 *
 * `paginate()` returns the whole `list` prop: rows, paging meta, the schema the
 * frontend renders (ListFilters.vue / useListQuery.js) and what was applied. Anything
 * outside the whitelist (unknown filter, bad value, unknown sort column) is ignored,
 * never passed to the database.
 *
 * Query string contract (matches the kit's useQueryState):
 *   search=foo   status=a        tags=a,b       is_mixture=1
 *   created_at_from=2026-01-01   created_at_to=2026-01-31
 *   mw_min=10.5                  mw_max=300
 *   sort=trade_name&dir=desc     page=2&per=25
 */
final class ListQuery
{
    private const SEARCH_MAX_LENGTH = 100;

    /** @var array{columns: list<string>, labelKey: string}|null */
    private ?array $search = null;

    /** @var array<string, array{type: FilterType, column: string, labelKey: string, options: list<array{value: string, label?: string, labelKey?: string}>|null, optionsUrl: string|null, using: callable|null}> */
    private array $filters = [];

    /** @var array<string, array{column: string, labelKey: string}> */
    private array $sorts = [];

    private string $defaultSort = '';

    private string $defaultDirection = 'asc';

    private int $defaultPerPage = 25;

    /** @var list<int> */
    private array $perPageOptions = [10, 25, 50, 100];

    private function __construct(private readonly Builder $query, private readonly Request $request) {}

    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    /**
     * Free-text search: every word must match at least one of the columns (case-insensitive).
     * A dotted column ("substance.name_en") searches that relation.
     *
     * @param  list<string>  $columns
     */
    public function search(array $columns, string $placeholderKey = 'filters.search_placeholder'): self
    {
        $this->search = ['columns' => $columns, 'labelKey' => $placeholderKey];

        return $this;
    }

    /**
     * One value from a list. `$options` is a backed enum class (labels come from
     * lang `enums.<snake_class>.<value>`), a list of values or option arrays, or null
     * when `$optionsUrl` serves a long list (any scalar is then accepted).
     *
     * `$using` replaces the plain `where(column, value)` for a filter that is not a column comparison (a value inside a
     * JSON column, a related row): `fn (Builder $query, string $value): mixed`. The value is whitelisted as usual.
     *
     * @param  class-string<BackedEnum>|list<string|array{value: string, label?: string, labelKey?: string}>|null  $options
     * @param  (callable(Builder, string): mixed)|null  $using
     */
    public function select(string $key, string|array|null $options = null, ?string $column = null, ?string $labelKey = null, ?string $optionsUrl = null, ?callable $using = null): self
    {
        return $this->addFilter(FilterType::Select, $key, $column, $labelKey, $options, $optionsUrl, $using);
    }

    /**
     * Several values from a list (`whereIn`), written as `key=a,b` in the URL.
     *
     * @param  class-string<BackedEnum>|list<string|array{value: string, label?: string, labelKey?: string}>|null  $options
     */
    public function multiselect(string $key, string|array|null $options = null, ?string $column = null, ?string $labelKey = null, ?string $optionsUrl = null): self
    {
        return $this->addFilter(FilterType::Multiselect, $key, $column, $labelKey, $options, $optionsUrl);
    }

    /** A toggle: `key=1` keeps rows where the column is true. Absent means no filter. */
    public function boolean(string $key, ?string $column = null, ?string $labelKey = null): self
    {
        return $this->addFilter(FilterType::Boolean, $key, $column, $labelKey);
    }

    /** `key_from` / `key_to` as Y-m-d dates, inclusive. */
    public function dateRange(string $key, ?string $column = null, ?string $labelKey = null): self
    {
        return $this->addFilter(FilterType::DateRange, $key, $column, $labelKey);
    }

    /** `key_min` / `key_max` as plain decimal strings, inclusive. Never cast to float. */
    public function numberRange(string $key, ?string $column = null, ?string $labelKey = null): self
    {
        return $this->addFilter(FilterType::NumberRange, $key, $column, $labelKey);
    }

    /**
     * The sortable columns. A list value sorts by that column under the same key;
     * a string key maps a public key to a column: ['name' => 'trade_name'].
     *
     * The sort control's labels come from lang `<labelPrefix>.<key>`.
     *
     * @param  array<int|string, string>  $columns
     */
    public function sortable(array $columns, string $default, string $direction = 'asc', string $labelPrefix = 'filters'): self
    {
        foreach ($columns as $key => $column) {
            $key = is_int($key) ? $column : $key;
            $this->sorts[$key] = ['column' => $column, 'labelKey' => "{$labelPrefix}.{$key}"];
        }

        $this->defaultSort = $default;
        $this->defaultDirection = $direction === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    /** @param  list<int>  $allowed */
    public function perPage(int $default, array $allowed = [10, 25, 50, 100]): self
    {
        $this->defaultPerPage = $default;
        $this->perPageOptions = $allowed;

        return $this;
    }

    /** The query with search, filters and sort applied (no pagination). */
    public function apply(): Builder
    {
        $query = clone $this->query;
        $applied = $this->applied();

        $this->applySearch($query, $applied['search']);

        foreach ($this->filters as $key => $filter) {
            $this->applyFilter($query, $key, $filter, $applied['filters']);
        }

        $query->reorder();

        if (isset($this->sorts[$applied['sort']])) {
            $query->orderBy($this->sorts[$applied['sort']]['column'], $applied['dir']);
        }

        // A stable tiebreaker, so rows never repeat or vanish between pages.
        $query->orderBy($query->getModel()->getQualifiedKeyName());

        return $query;
    }

    public function paginate(?callable $transform = null): array
    {
        $applied = $this->applied();
        $paginator = $this->apply()->paginate($applied['per'], ['*'], 'page', $applied['page']);

        return [
            'rows' => $this->rows($paginator, $transform),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'schema' => $this->schema(),
            'applied' => $applied,
        ];
    }

    /**
     * What the frontend renders: search, filters, sorts and defaults.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'search' => $this->search === null ? null : ['placeholderKey' => $this->search['labelKey']],
            'filters' => array_values(array_map(fn (array $filter, string $key): array => array_filter([
                'key' => $key,
                'type' => $filter['type']->value,
                'labelKey' => $filter['labelKey'],
                'options' => $filter['options'],
                'optionsUrl' => $filter['optionsUrl'],
            ], fn ($value): bool => $value !== null), $this->filters, array_keys($this->filters))),
            'sorts' => array_values(array_map(
                fn (array $sort, string $key): array => ['key' => $key, 'labelKey' => $sort['labelKey']],
                $this->sorts,
                array_keys($this->sorts),
            )),
            'defaults' => [
                'sort' => $this->defaultSort,
                'dir' => $this->defaultDirection,
                'per' => $this->defaultPerPage,
            ],
            'perPageOptions' => $this->perPageOptions,
        ];
    }

    /**
     * The request's state after the whitelist: only values the server will really apply.
     *
     * @return array{search: string, filters: array<string, mixed>, sort: string, dir: string, page: int, per: int}
     */
    public function applied(): array
    {
        $filters = [];

        foreach ($this->filters as $key => $filter) {
            $value = $this->readFilter($key, $filter);

            if ($value !== null) {
                $filters[$key] = $value;
            }
        }

        $sort = (string) $this->request->query('sort', '');
        $dir = strtolower((string) $this->request->query('dir', ''));
        $sortKnown = isset($this->sorts[$sort]);
        $per = (int) $this->request->query('per', $this->defaultPerPage);

        return [
            'search' => $this->readSearch(),
            'filters' => $filters,
            'sort' => $sortKnown ? $sort : $this->defaultSort,
            // The kit leaves a value equal to its default out of the URL, so a missing dir means the default.
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : $this->defaultDirection,
            'page' => max(1, (int) $this->request->query('page', 1)),
            'per' => in_array($per, $this->perPageOptions, true) ? $per : $this->defaultPerPage,
        ];
    }

    /**
     * @param  class-string<BackedEnum>|list<string|array{value: string, label?: string, labelKey?: string}>|null  $options
     */
    private function addFilter(FilterType $type, string $key, ?string $column, ?string $labelKey, string|array|null $options = null, ?string $optionsUrl = null, ?callable $using = null): self
    {
        $this->filters[$key] = [
            'using' => $using,
            'type' => $type,
            'column' => $column ?? $key,
            'labelKey' => $labelKey ?? "filters.{$key}",
            'options' => $this->normalizeOptions($options),
            'optionsUrl' => $optionsUrl,
        ];

        return $this;
    }

    /**
     * @param  class-string<BackedEnum>|list<string|array{value: string, label?: string, labelKey?: string}>|null  $options
     * @return list<array{value: string, label?: string, labelKey?: string}>|null
     */
    private function normalizeOptions(string|array|null $options): ?array
    {
        if ($options === null) {
            return null;
        }

        if (is_string($options)) {
            $prefix = 'enums.'.Str::snake(class_basename($options));

            return array_map(
                fn (BackedEnum $case): array => ['value' => (string) $case->value, 'labelKey' => "{$prefix}.{$case->value}"],
                $options::cases(),
            );
        }

        return array_values(array_map(
            fn (string|array $option): array => is_array($option) ? $option : ['value' => $option, 'label' => $option],
            $options,
        ));
    }

    private function readSearch(): string
    {
        if ($this->search === null) {
            return '';
        }

        $raw = $this->request->query('search', '');

        return is_string($raw) ? Str::limit(trim($raw), self::SEARCH_MAX_LENGTH, '') : '';
    }

    /**
     * @param  array{type: FilterType, column: string, labelKey: string, options: list<array{value: string}>|null, optionsUrl: string|null}  $filter
     */
    private function readFilter(string $key, array $filter): string|array|bool|null
    {
        $allowed = $filter['options'] === null ? null : array_column($filter['options'], 'value');

        return match ($filter['type']) {
            FilterType::Select => $this->readSelect($key, $allowed),
            FilterType::Multiselect => $this->readMultiselect($key, $allowed),
            FilterType::Boolean => in_array($this->request->query($key), ['1', 'true', 1, true], true) ? true : null,
            FilterType::DateRange => $this->readRange($key, '_from', '_to', fn (string $value): bool => $this->isDate($value)),
            FilterType::NumberRange => $this->readRange($key, '_min', '_max', fn (string $value): bool => (bool) preg_match('/^-?\d{1,30}(\.\d{1,12})?$/', $value)),
        };
    }

    /** @param  list<string>|null  $allowed */
    private function readSelect(string $key, ?array $allowed): ?string
    {
        $value = $this->request->query($key);

        if (! is_string($value) || $value === '' || strlen($value) > 255) {
            return null;
        }

        return $allowed === null || in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param  list<string>|null  $allowed
     * @return list<string>|null
     */
    private function readMultiselect(string $key, ?array $allowed): ?array
    {
        $raw = $this->request->query($key);

        // The kit writes arrays comma-joined, escaping a comma inside a value as %2C and % as %25.
        $items = is_array($raw)
            ? $raw
            : (is_string($raw) && $raw !== '' ? array_map(
                fn (string $item): string => str_replace(['%2C', '%2c', '%25'], [',', ',', '%'], $item),
                explode(',', $raw),
            ) : []);

        $items = array_values(array_unique(array_filter(
            $items,
            fn ($item): bool => is_string($item) && $item !== '' && strlen($item) <= 255 && ($allowed === null || in_array($item, $allowed, true)),
        )));

        return $items === [] ? null : $items;
    }

    /**
     * @param  callable(string): bool  $valid
     * @return array{from?: string, to?: string}|null
     */
    private function readRange(string $key, string $lowSuffix, string $highSuffix, callable $valid): ?array
    {
        $range = [];

        foreach (['from' => $lowSuffix, 'to' => $highSuffix] as $bound => $suffix) {
            $value = $this->request->query($key.$suffix);

            if (is_string($value) && $valid($value)) {
                $range[$bound] = $value;
            }
        }

        return $range === [] ? null : $range;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '' || $this->search === null) {
            return;
        }

        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';

            $query->where(function (Builder $group) use ($pattern): void {
                foreach ($this->search['columns'] as $column) {
                    $this->orWhereLike($group, $column, $pattern);
                }
            });
        }
    }

    private function orWhereLike(Builder $group, string $column, string $pattern): void
    {
        if (! str_contains($column, '.')) {
            $group->orWhere($group->qualifyColumn($column), 'ilike', $pattern);

            return;
        }

        [$relation, $related] = explode('.', $column, 2);
        $group->orWhereHas($relation, fn (Builder $inner) => $inner->where($inner->qualifyColumn($related), 'ilike', $pattern));
    }

    /**
     * @param  array{type: FilterType, column: string, using: callable|null}  $filter
     * @param  array<string, mixed>  $applied
     */
    private function applyFilter(Builder $query, string $key, array $filter, array $applied): void
    {
        if (! array_key_exists($key, $applied)) {
            return;
        }

        $value = $applied[$key];

        if ($filter['using'] !== null) {
            ($filter['using'])($query, $value);

            return;
        }
        $column = $query->qualifyColumn($filter['column']);

        match ($filter['type']) {
            FilterType::Select => $query->where($column, $value),
            FilterType::Multiselect => $query->whereIn($column, $value),
            FilterType::Boolean => $query->where($column, true),
            FilterType::DateRange => $this->applyRange($query, $column, $value, fn (Builder $q, string $op, string $bound) => $q->whereDate($column, $op, $bound)),
            FilterType::NumberRange => $this->applyRange($query, $column, $value, fn (Builder $q, string $op, string $bound) => $q->where($column, $op, $bound)),
        };
    }

    /**
     * @param  array{from?: string, to?: string}  $range
     * @param  callable(Builder, string, string): mixed  $constrain
     */
    private function applyRange(Builder $query, string $column, array $range, callable $constrain): void
    {
        if (isset($range['from'])) {
            $constrain($query, '>=', $range['from']);
        }

        if (isset($range['to'])) {
            $constrain($query, '<=', $range['to']);
        }
    }

    /**
     * @return list<mixed>
     */
    private function rows(LengthAwarePaginator $paginator, ?callable $transform): array
    {
        return collect($paginator->items())
            ->map(fn (Model $model) => $transform ? $transform($model) : $model->toArray())
            ->values()
            ->all();
    }
}
