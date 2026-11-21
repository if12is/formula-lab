<?php

namespace Tests\Feature\I18n;

use App\Support\Translations;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The engine only returns message keys; lang/{ar,en}/engine.php words them. Every key the engine can emit as a reason or a
 * warning must have a text in both languages, or the editor would print a raw key.
 */
class EngineKeysTest extends TestCase
{
    /** Quoted lowercase keys in the engine that are result names, property names or trace labels, not messages. */
    private const NOT_MESSAGES = ['cost.batch', 'cost.packaging', 'batch.remainder', 'input.days', 'water.ratio', 'compliance.overall'];

    /** @return list<string> */
    private function engineMessageKeys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('../../packages/engine/src'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'ts' || str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            preg_match_all('/[\'"`]((?:formula|cost|confidence|input|density|batch|engine|soap|water|neutralize|active|alcohol|fragrance|percent_from_weight|compliance|inci|ifra|dsl|tax)\.[a-z0-9_]+)(?![A-Za-z0-9_.])/', (string) file_get_contents($file->getPathname()), $matches);
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_diff(array_unique($keys), self::NOT_MESSAGES));
    }

    /** @return array<string, string> */
    private function texts(string $locale): array
    {
        return Arr::dot(require lang_path("{$locale}/engine.php"));
    }

    public function test_the_scan_finds_the_engines_message_keys(): void
    {
        $keys = $this->engineMessageKeys();

        foreach (['formula.sum_not_100', 'formula.qs_balance_not_positive', 'cost.price_missing', 'confidence.l7_requires_signed_override', 'confidence.below_policy', 'input.missing', 'engine.version_mismatch', 'soap.no_oils', 'water.concentration_must_be_below_100', 'neutralize.theoretical_start_final_ph_measured', 'active.target_exceeds_raw', 'alcohol.basis_required', 'fragrance.ifra_unknown', 'percent_from_weight.batch_not_positive', 'compliance.composition_missing', 'compliance.ruleset_invalid', 'compliance.rule_pending_source', 'inci.composition_incomplete', 'ifra.certificate_missing', 'dsl.source_missing', 'dsl.pending_source_only_in_draft', 'tax.setting_missing', 'tax.setting_invalid', 'tax.regime_missing'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_every_engine_message_key_has_a_text_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = $this->texts($locale);

            foreach ($this->engineMessageKeys() as $key) {
                $this->assertArrayHasKey($key, $texts, "lang/{$locale}/engine.php lacks [{$key}]");
                $this->assertNotSame('', trim($texts[$key]));
            }
        }
    }

    /** The message keys the engine puts in a rule or IFRA result are written in three parts, so the scan above cannot see them. */
    public function test_the_three_part_message_keys_of_the_compliance_engine_have_texts(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = $this->texts($locale);

            foreach (['compliance.methanol.forbidden', 'compliance.ifra.fragrance_limit', 'compliance.rule.custom'] as $key) {
                $this->assertArrayHasKey($key, $texts, "lang/{$locale}/engine.php lacks [{$key}]");
                $this->assertNotSame('', trim($texts[$key]));
            }
        }
    }

    /** The message key a rule shipped in packages/engine/data/rules/*.json uses must be worded too. */
    public function test_every_message_key_of_a_shipped_rule_file_has_a_text(): void
    {
        foreach (glob(base_path('../../packages/engine/data/rules/*.json')) as $file) {
            $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            foreach ($document['rules'] as $rule) {
                foreach (['ar', 'en'] as $locale) {
                    $this->assertArrayHasKey($rule['messageKey'], $this->texts($locale), "{$file}: [{$rule['messageKey']}] is not worded in {$locale}");
                }
            }
        }
    }

    public function test_both_languages_word_the_same_keys_with_the_same_placeholders(): void
    {
        $ar = $this->texts('ar');
        $en = $this->texts('en');

        $this->assertSame(array_keys($en), array_keys($ar));

        foreach ($en as $key => $text) {
            preg_match_all('/\{(\w+)\}/', $text, $english);
            preg_match_all('/\{(\w+)\}/', $ar[$key], $arabic);
            $this->assertSame($english[1], $arabic[1], "placeholders of [{$key}] differ");
        }
    }

    public function test_the_texts_are_shared_with_the_frontend(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('translations', fn ($translations) => isset($translations['engine.formula.sum_not_100'], $translations['engine.confidence.l7_requires_signed_override'])));
    }

    public function test_the_keys_the_screens_use_exist_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $dictionary = Translations::for($locale);

            foreach (['engine.formula.sum_not_100', 'materials.title', 'formulas.title', 'formulas.cost.not_reliable_estimate', 'materials.resolve.reasons.lower_confidence', 'formulas.totals.balance', 'compliance.title', 'compliance.panel.title', 'formulas.label.title', 'materials.composition.add', 'enums.rule_operator.<=', 'enums.check_status.NOT_APPLICABLE', 'engine.dsl.source_missing', 'engine.inci.name_missing', 'engine.ifra.category_missing'] as $key) {
                $this->assertArrayHasKey($key, $dictionary, "[{$key}] missing in {$locale}");
            }
        }
    }
}
