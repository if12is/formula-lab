<?php

namespace Tests\Feature\Families;

use App\Models\Formula;
use App\Models\ProductFamily;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Lab\LabCoverageService;
use App\Support\Families\FamilyData;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** K-04 / K-05 / N-12: the new family presets, the claims a product makes and the data the separate EDA path notice reads. */
class DisinfectantClaimsTest extends TestCase
{
    use RefreshDatabase;

    private function formulaOf(string $familyCode): Formula
    {
        $this->seed(ProductFamilySeeder::class);
        $formula = Formula::factory()->create(['product_type' => $familyCode, 'product_family_id' => ProductFamily::query()->where('code', $familyCode)->value('id')]);
        app(FormulaVersionService::class)->createVersion($formula, [['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '100']], null);

        return $formula;
    }

    public function test_the_new_family_presets_are_what_the_research_states(): void
    {
        $this->seed(ProductFamilySeeder::class);
        $softener = ProductFamily::query()->where('code', 'fabric_softener')->sole();
        $fragrance = ProductFamily::query()->where('code', 'laundry_fragrance')->sole();
        $alcohol = ProductFamily::query()->where('code', 'alcohol_disinfectant')->sole();
        $quat = ProductFamily::query()->where('code', 'quat_disinfectant')->sole();

        $this->assertSame(['active_matter'], $softener->required_calculations);
        $this->assertSame(['viscosity', 'stability_4w'], $softener->measured_properties);
        $this->assertSame('10A', $fragrance->ifra_category);
        $this->assertSame('disinfectants', $alcohol->group);
        $this->assertSame('EDA', $alcohol->regulator->value);
        $this->assertNull($alcohol->ifra_category);
        $this->assertSame(['disinfectant_active', 'alcohol_dilution'], $alcohol->required_calculations);
        $this->assertSame(['disinfectant_active', 'dilution'], $quat->required_calculations);
    }

    public function test_the_lab_demands_only_what_it_can_record_for_the_new_families(): void
    {
        $required = fn (string $code): array => app(LabCoverageService::class)->requiredFor($this->formulaOf($code)->fresh());

        $this->assertSame(['viscosity', 'stability_4w'], $required('fabric_softener'));
        // Ethanol strength (%v/v) has no lab property yet, so nothing is demanded for the alcohol family.
        $this->assertSame([], $required('alcohol_disinfectant'));
        $this->assertSame(['active_matter'], $required('quat_disinfectant'));
    }

    public function test_the_claims_are_saved_on_the_formula_without_repeats_and_can_be_cleared(): void
    {
        $formula = $this->formulaOf('shampoo');

        $this->put("/formulas/{$formula->getKey()}/claims", ['claims' => ['disinfection', 'disinfection', 'antibacterial']])->assertSessionHas('success');
        $this->assertSame(['disinfection', 'antibacterial'], $formula->fresh()->claims);

        $this->put("/formulas/{$formula->getKey()}/claims", ['claims' => []])->assertSessionHasNoErrors();
        $this->assertNull($formula->fresh()->claims);
    }

    public function test_a_claim_the_flag_data_does_not_know_is_refused_in_the_users_language(): void
    {
        $formula = $this->formulaOf('shampoo');

        $this->put("/formulas/{$formula->getKey()}/claims", ['claims' => ['germ_free']])->assertSessionHasErrors('claims.0');
        $this->put("/formulas/{$formula->getKey()}/claims", [])->assertSessionHasErrors('claims');
        $this->assertNull($formula->fresh()->claims);
    }

    public function test_the_editor_is_given_the_claims_and_the_family_group_the_engine_reads(): void
    {
        $formula = $this->formulaOf('alcohol_disinfectant');
        $formula->update(['claims' => ['disinfection']]);

        $this->get(route('formulas.versions.edit', [$formula, 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Editor')
            ->where('formula.claims', ['disinfection'])
            ->where('formula.family_group', 'disinfectants'));

        $plain = $this->formulaOf('shampoo');
        $this->get(route('formulas.versions.edit', [$plain, 1]))->assertInertia(fn (Assert $page) => $page
            ->where('formula.claims', [])
            ->where('formula.family_group', 'personal_care'));
    }

    public function test_the_flag_data_names_the_eda_guide_and_leaves_the_antibacterial_claim_undecided(): void
    {
        $flag = app(FamilyData::class)->disinfectantFlag();

        $this->assertSame(['regulator' => 'EDA', 'guideCode' => 'EDREX:GL.CALI.009', 'guideEdition' => '2026', 'sourceId' => 'plan-00-research-03'], $flag['pathway']);
        $this->assertSame(['disinfection' => true, 'antibacterial' => null], collect($flag['claims'])->mapWithKeys(fn (array $c): array => [$c['code'] => $c['triggersPathway']])->all());
    }

    public function test_every_claim_the_data_names_has_a_text_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/disinfection.php"));

            foreach (app(FamilyData::class)->claimCodes() as $code) {
                $this->assertNotSame('', trim($texts["claims.{$code}"] ?? ''), "{$locale} disinfection.claims.{$code}");
            }
        }
    }

    public function test_both_languages_word_the_same_notice_keys(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('en/disinfection.php'))), array_keys(Arr::dot(require lang_path('ar/disinfection.php'))));
    }
}
