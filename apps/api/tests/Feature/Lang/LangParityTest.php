<?php

namespace Tests\Feature\Lang;

use Illuminate\Support\Arr;
use Tests\TestCase;

class LangParityTest extends TestCase
{
    /** @return list<string> */
    private function langGroups(string $locale): array
    {
        return array_map(
            fn (string $path): string => basename($path, '.php'),
            glob(lang_path($locale.'/*.php')),
        );
    }

    public function test_every_lang_file_exists_in_both_locales(): void
    {
        $ar = $this->langGroups('ar');
        $en = $this->langGroups('en');
        sort($ar);
        sort($en);

        $this->assertNotSame([], $ar);
        $this->assertSame($ar, $en);
    }

    public function test_every_lang_file_has_the_same_keys_in_both_locales(): void
    {
        foreach ($this->langGroups('ar') as $group) {
            // Read the project files themselves: trans() would merge in the framework's own English validation lines.
            $ar = array_keys(Arr::dot(require lang_path("ar/{$group}.php")));
            $en = array_keys(Arr::dot(require lang_path("en/{$group}.php")));
            sort($ar);
            sort($en);

            $this->assertNotSame([], $ar, "lang group [{$group}] is empty");
            $this->assertSame($ar, $en, "lang group [{$group}] differs between ar and en");
        }
    }

    public function test_every_shared_group_is_a_lang_file(): void
    {
        foreach (config('ui.shared_groups') as $group) {
            $this->assertContains($group, $this->langGroups('ar'), "shared group [{$group}] has no lang file");
        }
    }
}
