<?php

namespace Tests\Feature\Terms;

use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\Terms\TermsAcceptanceService;
use App\Support\Clock\Clock;
use App\Support\Clock\FixedClock;
use App\Support\Terms\TermsDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** S-16: the terms-of-use gate. Acceptance is recorded with the version, the wording hash and the injected clock's time, and asked again when the version or wording changes. */
class TermsGateTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        config(['terms.enforce' => true]);
        $this->clock = new FixedClock(CarbonImmutable::parse('2026-11-23T10:15:00Z'));
        $this->app->instance(Clock::class, $this->clock);
        // One browser: the same session cookie on every request of the test (the test client encrypts it like Laravel does).
        $this->withCookie(config('session.cookie'), self::SESSION_ID);
    }

    private function accept(array $overrides = [])
    {
        return $this->post(route('terms.accept'), ['accepted' => true, 'version' => TermsDocument::version(), ...$overrides]);
    }

    public function test_a_page_sends_a_person_who_has_not_accepted_to_the_terms_and_back_after(): void
    {
        $this->get('/materials')->assertRedirect(route('terms.show'));

        $this->get(route('terms.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Terms/Show')
            ->where('gated', true)
            ->where('acceptance', null)
            ->where('document.version', config('terms.version'))
            ->where('document.hash', TermsDocument::hash())
            ->where('document.draft', true)
            ->has('document.sections', 5));

        $this->accept()->assertRedirect('/materials')->assertSessionHas('success');

        $this->get('/materials')->assertOk();
    }

    public function test_the_acceptance_is_recorded_with_version_hash_and_the_injected_clock_time(): void
    {
        $this->accept()->assertSessionHasNoErrors();

        $row = TermsAcceptance::query()->sole();
        $this->assertSame(config('terms.version'), $row->terms_version);
        $this->assertSame(TermsDocument::hash(), $row->terms_hash);
        $this->assertSame('2026-11-23T10:15:00+00:00', $row->accepted_at->toIso8601String(), 'the time is the clock\'s, not the database default');
        $this->assertNull($row->user_id, 'nullable until sign-in exists (U-01)');
        $this->assertSame(hash('sha256', self::SESSION_ID), $row->session_key, 'the session id is stored hashed');
    }

    public function test_it_is_asked_again_when_the_version_changes(): void
    {
        $this->accept();
        $this->get('/materials')->assertOk();

        config(['terms.version' => '2027-01-final-1']);

        $this->get('/materials')->assertRedirect(route('terms.show'));
        $this->accept()->assertRedirect('/materials');
        $this->assertSame(['2026-11-draft-1', '2027-01-final-1'], TermsAcceptance::query()->orderBy('accepted_at')->orderBy('terms_version')->pluck('terms_version')->all());
    }

    public function test_it_is_asked_again_when_the_wording_changes_without_a_version_bump(): void
    {
        $this->accept();
        $before = TermsDocument::hash();

        app('translator')->addLines(['terms.sections.sources.body' => 'A changed sentence.'], 'en');
        app('translator')->addLines(['terms.sections.sources.body' => 'جملة متغيرة.'], 'ar');

        $this->assertNotSame($before, TermsDocument::hash());
        $this->get('/materials')->assertRedirect(route('terms.show'));
    }

    public function test_ticking_nothing_records_nothing(): void
    {
        $this->accept(['accepted' => false])->assertSessionHasErrors('accepted');
        $this->post(route('terms.accept'), ['version' => TermsDocument::version()])->assertSessionHasErrors('accepted');

        $this->assertSame(0, TermsAcceptance::query()->count());
        $this->get('/materials')->assertRedirect(route('terms.show'));
    }

    public function test_a_version_that_was_on_screen_but_is_no_longer_current_is_refused_with_a_translated_message(): void
    {
        $this->accept(['version' => 'old-version'])->assertSessionHasErrors(['version' => __('exceptions.terms_version_changed', ['submitted' => 'old-version', 'current' => config('terms.version')])]);

        $this->assertSame(0, TermsAcceptance::query()->count());
    }

    public function test_the_terms_screen_and_the_preference_switches_stay_open(): void
    {
        $this->get(route('terms.show'))->assertOk();
        $this->put(route('settings.locale'), ['locale' => 'en'])->assertRedirect();
        $this->put(route('settings.theme'), ['preference' => 'dark'])->assertRedirect();
    }

    public function test_a_post_before_acceptance_is_not_run_and_returns_home_after_accepting(): void
    {
        $this->post(route('exports.store'), ['kind' => 'lot_trace', 'lot' => 'L-1'])->assertRedirect(route('terms.show'));
        $this->accept()->assertRedirect(route('home', [], false));
    }

    public function test_a_json_call_before_acceptance_is_refused_with_403(): void
    {
        $this->postJson(route('exports.store'), ['kind' => 'lot_trace', 'lot' => 'L-1'])->assertForbidden();
    }

    public function test_a_signed_in_person_is_recognised_by_account_on_another_browser(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->accept()->assertSessionHasNoErrors();
        $this->assertSame($user->getKey(), TermsAcceptance::query()->sole()->user_id);

        $this->withCookie(config('session.cookie'), str_repeat('b', 40))->actingAs($user)->get('/materials')->assertOk();
    }

    public function test_the_gate_is_off_when_not_enforced(): void
    {
        config(['terms.enforce' => false]);

        $this->get('/materials')->assertOk();
    }

    public function test_the_service_answers_who_has_accepted(): void
    {
        $service = app(TermsAcceptanceService::class);
        $this->assertFalse($service->hasAccepted(null, 's-1'));

        $service->accept(null, 's-1', TermsDocument::version());

        $this->assertTrue($service->hasAccepted(null, 's-1'));
        $this->assertFalse($service->hasAccepted(null, 's-2'));
    }

    public function test_an_acceptance_cannot_be_changed_or_deleted(): void
    {
        $this->accept();
        $row = TermsAcceptance::query()->sole();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $row->update(['terms_version' => 'x']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $row->delete());
    }

    public function test_the_wording_exists_in_both_languages_and_is_marked_as_a_draft(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $sections = TermsDocument::sections($locale);
            $this->assertCount(5, $sections);
            $this->assertSame(['purpose', 'lab_trial', 'assistant', 'sources', 'legal_pending'], array_column($sections, 'key'));
            $this->assertStringContainsString($locale === 'en' ? 'DRAFT PLACEHOLDER' : 'مسودة', $sections[4]['body'], 'the legal clause is a marked placeholder, not invented text');
        }
    }
}
