<?php

namespace Tests\Feature\Eda;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\EdaItemStatus;
use App\Enums\Regulator;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * N-08 against the real engine script. Items come from a TEST-FIXTURE data file (tests/fixtures/eda-checklist.json: invented document names) because
 * the shipped file lists none: the project sources do not state what an EDA notification file contains.
 */
class EdaChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Formula $skincare;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('eda.disk'));
    }

    private function useFixtureItems(): void
    {
        config(['eda.definitions_path' => base_path('tests/fixtures/eda-checklist.json')]);
    }

    private function product(string $group, Regulator $regulator = Regulator::Eda, string $code = 'P-1'): Formula
    {
        $family = ProductFamily::factory()->create(['group' => $group, 'regulator' => $regulator]);

        return Formula::factory()->create(['tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'code' => $code, 'product_family_id' => $family->id]);
    }

    /** @param  array<string, mixed>  $data */
    private function record(Formula $formula, string $item, array $data)
    {
        return $this->post("/eda/{$formula->id}/items/{$item}", $data);
    }

    public function test_with_the_shipped_data_no_list_is_invented_and_the_file_is_never_ready(): void
    {
        $formula = $this->product('skincare');

        $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Eda/Show')
            ->where('readiness.state', 'items_not_sourced')
            ->where('readiness.counts', null)
            ->where('items', [])
            ->where('readiness.reference.title', fn (string $title) => str_contains($title, '122')));
    }

    public function test_nothing_can_be_recorded_while_the_list_is_not_sourced(): void
    {
        $formula = $this->product('skincare');

        $this->record($formula, 'anything', ['status' => 'attached', 'document_title' => 'x'])->assertSessionHas('error', __('exceptions.eda_items_not_sourced'));
        $this->assertSame(0, EdaChecklistEntry::query()->count());
    }

    public function test_a_product_of_an_eos_family_or_without_a_family_is_out_of_scope(): void
    {
        $eos = $this->product('detergents', Regulator::Eos, 'P-EOS');
        $bare = Formula::factory()->create(['tenant_id' => $eos->tenant_id, 'code' => 'P-BARE']);

        foreach ([$eos, $bare] as $formula) {
            $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page->where('readiness.state', 'out_of_scope')->where('items', []));
        }
    }

    public function test_items_start_missing_and_the_readiness_follows_what_is_recorded(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');

        $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'incomplete')
            ->where('readiness.counts', ['total' => 3, 'attached' => 0, 'missing' => 3, 'notApplicable' => 0])
            ->where('items.0.status', 'missing'));

        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Certificate A', 'document_reference' => 'REF-1'])->assertSessionHas('success');
        $this->record($formula, 'test_doc_b', ['status' => 'not_applicable', 'note' => 'Product has no such part'])->assertSessionHas('success');
        $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'incomplete')
            ->where('readiness.counts', ['total' => 3, 'attached' => 1, 'missing' => 1, 'notApplicable' => 1])
            ->where('items.0.document_title', 'Certificate A')
            ->where('items.1.note', 'Product has no such part'));

        $this->record($formula, 'test_doc_c', ['status' => 'attached', 'document_title' => 'Certificate C']);
        $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page->where('readiness.state', 'ready'));
    }

    public function test_setting_an_item_back_to_missing_removes_the_record_and_its_file(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');
        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert', 'file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf')]);
        $path = EdaChecklistEntry::query()->firstOrFail()->file_path;
        Storage::disk(config('eda.disk'))->assertExists($path);

        $this->record($formula, 'test_doc_a', ['status' => 'missing'])->assertSessionHas('success');

        $this->assertSame(0, EdaChecklistEntry::query()->count());
        Storage::disk(config('eda.disk'))->assertMissing($path);
    }

    public function test_an_attached_item_needs_a_title_and_a_not_applicable_item_needs_a_reason(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');

        $this->record($formula, 'test_doc_a', ['status' => 'attached'])->assertSessionHasErrors('document_title');
        $this->record($formula, 'test_doc_a', ['status' => 'not_applicable'])->assertSessionHasErrors('note');
        $this->record($formula, 'test_doc_a', ['status' => 'done'])->assertSessionHasErrors('status');
        $this->assertSame(0, EdaChecklistEntry::query()->count());
    }

    public function test_an_item_the_checklist_does_not_have_is_refused(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');

        $this->record($formula, 'invented_doc', ['status' => 'attached', 'document_title' => 'x'])->assertSessionHas('error', __('exceptions.eda_item_unknown', ['item' => 'invented_doc']));
        $this->assertSame(0, EdaChecklistEntry::query()->count());
    }

    public function test_a_file_is_kept_replaced_and_downloaded(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('personal_care');
        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert', 'file' => UploadedFile::fake()->create('first.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $first = EdaChecklistEntry::query()->firstOrFail()->file_path;

        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert v2', 'file' => UploadedFile::fake()->create('second.pdf', 20, 'application/pdf')]);

        $entry = EdaChecklistEntry::query()->firstOrFail();
        $this->assertSame('second.pdf', $entry->file_name);
        Storage::disk(config('eda.disk'))->assertMissing($first);
        $this->get("/eda/{$formula->id}/items/test_doc_a/file")->assertOk()->assertDownload('second.pdf');
    }

    public function test_a_file_of_another_type_is_refused(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');

        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert', 'file' => UploadedFile::fake()->create('macro.exe', 20)])->assertSessionHasErrors('file');
    }

    public function test_changing_to_not_applicable_drops_the_document_details(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');
        $this->record($formula, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert', 'document_reference' => 'R-1', 'file' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf')]);
        $path = EdaChecklistEntry::query()->firstOrFail()->file_path;

        $this->record($formula, 'test_doc_a', ['status' => 'not_applicable', 'note' => 'Not for this product']);

        $entry = EdaChecklistEntry::query()->firstOrFail();
        $this->assertSame(EdaItemStatus::NotApplicable, $entry->status);
        $this->assertNull($entry->document_title);
        $this->assertNull($entry->file_path);
        Storage::disk(config('eda.disk'))->assertMissing($path);
    }

    public function test_an_entry_for_an_item_removed_from_the_data_is_listed_and_not_counted(): void
    {
        $this->useFixtureItems();
        $formula = $this->product('skincare');
        EdaChecklistEntry::factory()->create(['formula_id' => $formula->id, 'item_code' => 'removed_doc']);
        EdaChecklistEntry::factory()->create(['formula_id' => $formula->id, 'item_code' => 'test_doc_a']);

        $this->get("/eda/{$formula->id}")->assertInertia(fn (Assert $page) => $page
            ->where('readiness.orphans', ['removed_doc'])
            ->where('readiness.counts.attached', 1));
    }

    public function test_the_list_shows_only_eda_products_with_their_state_filters_and_summary(): void
    {
        $this->useFixtureItems();
        $skin = $this->product('skincare', code: 'P-SKIN');
        $this->product('disinfectants', code: 'P-DIS');
        $this->product('detergents', Regulator::Eos, 'P-EOS');
        $this->record($skin, 'test_doc_a', ['status' => 'attached', 'document_title' => 'Cert']);

        $this->get('/eda')->assertInertia(fn (Assert $page) => $page
            ->component('Eda/Index')
            ->where('list.meta.total', 2)
            ->where('list.rows.0.code', 'P-DIS')
            ->where('list.rows.0.state', 'items_not_sourced')
            ->where('list.rows.1.state', 'incomplete')
            ->where('list.rows.1.counts.attached', 1)
            ->where('summary', ['total' => 2, 'states' => ['items_not_sourced' => 1, 'incomplete' => 1]]));

        $this->get('/eda?group=skincare')->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1)->where('list.rows.0.code', 'P-SKIN'));
        $this->get('/eda?search=DIS')->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1)->where('list.rows.0.code', 'P-DIS'));
    }

    public function test_the_list_is_empty_when_no_product_is_under_eda(): void
    {
        $this->product('detergents', Regulator::Eos);

        $this->get('/eda')->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 0)->where('summary.total', 0));
    }
}
