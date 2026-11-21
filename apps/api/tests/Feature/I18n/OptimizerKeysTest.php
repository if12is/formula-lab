<?php

namespace Tests\Feature\I18n;

use Illuminate\Support\Arr;
use Tests\TestCase;

class OptimizerKeysTest extends TestCase
{
    /** @return array{keys: list<string>, params: array<string, list<string>>} */
    private function contract(): array
    {
        $path = base_path('../../packages/optimizer/messages.keys.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function flat(string $locale): array
    {
        // Read the project file itself: trans() would merge in framework lines.
        return Arr::dot(require lang_path("{$locale}/optimizer.php"));
    }

    public function test_every_optimizer_key_exists_in_both_locales(): void
    {
        $contract = $this->contract();
        $this->assertNotSame([], $contract['keys']);

        foreach (['ar', 'en'] as $locale) {
            $lang = $this->flat($locale);
            foreach ($contract['keys'] as $key) {
                $short = substr($key, strlen('optimizer.'));
                $this->assertArrayHasKey($short, $lang, "[{$locale}] missing {$key}");
                $this->assertNotSame('', trim($lang[$short]), "[{$locale}] empty {$key}");
            }
        }
    }

    public function test_lang_files_have_no_keys_the_optimizer_cannot_emit(): void
    {
        $expected = array_map(fn (string $k): string => substr($k, strlen('optimizer.')), $this->contract()['keys']);
        sort($expected);

        foreach (['ar', 'en'] as $locale) {
            $actual = array_keys($this->flat($locale));
            sort($actual);
            $this->assertSame($expected, $actual, "[{$locale}] optimizer.php differs from messages.keys.json");
        }
    }

    public function test_placeholders_only_use_declared_params(): void
    {
        $contract = $this->contract();

        foreach (['ar', 'en'] as $locale) {
            $lang = $this->flat($locale);
            foreach ($contract['keys'] as $key) {
                preg_match_all('/:([A-Za-z]+)/', $lang[substr($key, strlen('optimizer.'))], $m);
                foreach ($m[1] as $placeholder) {
                    $this->assertContains($placeholder, $contract['params'][$key], "[{$locale}] {$key} uses undeclared :{$placeholder}");
                }
            }
        }
    }
}
