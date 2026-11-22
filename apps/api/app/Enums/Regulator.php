<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The authority that licenses a product family's factory (plan/00-research/05). Labels live in lang families.regulators. */
enum Regulator: string
{
    use HasOptions;

    case Eos = 'EOS';
    case Eda = 'EDA';

    public function label(): string
    {
        return __("families.regulators.{$this->value}");
    }
}
