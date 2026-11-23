<?php

namespace App\Support\Engine;

use Illuminate\Support\Facades\Lang;

/**
 * Words for an engine gap (`{ materialId?, property, reason }`) that the screens' shared list (EngineMissing) cannot find on its own: a
 * subject the screen names (from the lang group `$subjects`, by the whole property, its last part (`waterfall.unitCost` -> `unitCost`) or the part before a colon (`tax:<id>:vat` -> `tax`)),
 * a material by its name, and the reason of an optimizer key (`optimizer.*`, whose texts live in lang/<locale>/optimizer.php). A gap this
 * cannot word is returned as it is, and the frontend words it from the engine's lang group.
 */
final class GapText
{
    /**
     * @param  array<string, mixed>  $gap
     * @param  array<string, string>  $names  Material names by id.
     * @return array<string, mixed>
     */
    public static function present(array $gap, array $names = [], string $subjects = ''): array
    {
        $property = (string) $gap['property'];
        $reason = (string) $gap['reason'];
        $last = substr($property, (int) strrpos('.'.$property, '.') - 0);
        $subject = null;
        foreach ([$property, $last, explode(':', $property)[0]] as $candidate) {
            if ($subjects !== '' && Lang::has("{$subjects}.{$candidate}")) {
                $subject = __("{$subjects}.{$candidate}");
                break;
            }
        }
        $head = explode(':', $reason)[0];

        return [
            ...$gap,
            'subject' => $subject,
            'text' => str_starts_with($head, 'optimizer.') && Lang::has($head) ? __($head) : null,
            'materialId' => isset($gap['materialId']) ? ($names[(string) $gap['materialId']] ?? (string) $gap['materialId']) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $gaps
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    public static function all(array $gaps, array $names = [], string $subjects = ''): array
    {
        return array_map(fn (array $gap): array => self::present($gap, $names, $subjects), $gaps);
    }
}
