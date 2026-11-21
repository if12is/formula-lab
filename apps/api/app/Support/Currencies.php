<?php

namespace App\Support;

/**
 * The currency registry, one source for every screen and rule: the base currency, the supported codes and their
 * minor units come from config/currencies.php; names, short names and symbols from lang/<locale>/currencies.php.
 * Select options, validation (`Rule::in(Currencies::codes())`) and labels all read this class.
 */
final class Currencies
{
    /** The base currency (EGP): costs are computed and stored in it. */
    public static function base(): string
    {
        return config('currencies.base');
    }

    /** @return list<string> Every supported ISO code, the base first. */
    public static function codes(): array
    {
        return array_keys(config('currencies.supported'));
    }

    /** @return list<string> The supported codes that are not the base: the ones that need an exchange rate. */
    public static function foreignCodes(): array
    {
        return array_values(array_filter(self::codes(), fn (string $code): bool => ! self::isBase($code)));
    }

    public static function isSupported(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    public static function isBase(string $code): bool
    {
        return $code === self::base();
    }

    public static function minorUnits(string $code): int
    {
        return (int) config("currencies.supported.{$code}.minor_units", 2);
    }

    public static function name(string $code): string
    {
        return self::text($code, 'name');
    }

    /** The short name used in tight places */
    public static function short(string $code): string
    {
        return self::text($code, 'short');
    }

    public static function symbol(string $code): string
    {
        return self::text($code, 'symbol');
    }

    /** "Name (CODE)": the label a select shows. */
    public static function label(string $code): string
    {
        return self::name($code).' ('.$code.')';
    }

    /**
     * Options for a select or a filter.
     *
     * @return list<array{value: string, label: string, short: string, symbol: string}>
     */
    public static function options(bool $includeBase = true): array
    {
        return array_map(
            fn (string $code): array => ['value' => $code, 'label' => self::label($code), 'short' => self::short($code), 'symbol' => self::symbol($code)],
            $includeBase ? self::codes() : self::foreignCodes(),
        );
    }

    private static function text(string $code, string $field): string
    {
        $key = "currencies.{$code}.{$field}";
        $text = __($key);

        return $text === $key ? $code : $text;
    }
}
