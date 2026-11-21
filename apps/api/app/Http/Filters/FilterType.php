<?php

namespace App\Http\Filters;

/** The filter kinds a list page can offer. The values match the frontend schema (ListFilters.vue). */
enum FilterType: string
{
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Boolean = 'boolean';
    case DateRange = 'date-range';
    case NumberRange = 'number-range';
}
