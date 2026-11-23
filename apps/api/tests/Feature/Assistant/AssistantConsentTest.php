<?php

namespace Tests\Feature\Assistant;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Enums\Assistant\AssistantConsentEventType;
use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Exceptions\Domain\AssistantException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Listeners\RevokeAssistantConsentOnLogout;
use App\Models\AssistantConsentEvent;
use App\Models\AssistantDisclosure;
use App\Models\CoaExtraction;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Assistant\AssistantConsentService;
use App\Services\Coa\CoaExtractionService;
use App\Support\Assistant\AssistantSessionKey;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Clock\Clock;
use App\Support\Clock\FixedClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\Concerns\GrantsAssistantConsent;
use Tests\TestCase;

/**
 * P-10: formulas, costs and supplier certificates reach the provider only with the browser session's consent. Time comes from an injected clock,
 * the consent and disclosure logs are append-only, and a tool without consent is refused (the model reads the refusal) and logged.
 * Fixture: material A 60 % at 10 and B 40 % at 25 EGP/kg cost 16 EGP per kg.
 */
class AssistantConsentTest extends TestCase
{
    use BuildsCoaFixtures, BuildsCostedFormulas, GrantsAssistantConsent, RefreshDatabase;

    private FixedClock $clock;

    /** These tests decide the consent themselves. */
    protected function coaSessionConsents(): bool
    {
        return false;
    }

    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock(CarbonImmutable::parse('2026-11-24T09:00:00Z'));
        $this->app->instance(Clock::class, $this->clock);
        $this->setUpCoa();
        $this->runUnderConsentSession('session-under-test');
        $this->versionId = $this->costedProduct(Tenant::factory()->create()->id)->versions()->firstOrFail()->id;
    }

    private function service(): AssistantConsentService
    {
        return app(AssistantConsentService::class);
    }

    private function ask(string $question = 'كام تكلفة التركيبة دي؟', array $history = []): TestResponse
    {
        return $this->postJson('/api/assistant/ask', ['question' => $question, 'history' => $history]);
    }

    /** @return array<string, mixed> The tool_result block the model was sent for its first tool call. */
    private function firstToolResult(): array
    {
        return $this->model->lastToolResults()[0];
    }

    public function test_a_consent_has_a_clock_timestamp_and_expires_with_the_clock(): void
    {
        $this->assertFalse($this->service()->state($this->consentKey)->granted());

        $state = $this->service()->grant($this->consentKey, [AssistantDataCategory::FormulaCost, AssistantDataCategory::FormulaCost]);

        $this->assertTrue($state->granted());
        $this->assertSame([AssistantDataCategory::FormulaCost], $state->categories);
        $this->assertSame('2026-11-24T09:00:00+00:00', $state->grantedAt->toIso8601String());
        $this->assertSame('2026-11-24T09:00:00+00:00', AssistantConsentEvent::query()->sole()->occurred_at->toIso8601String());

        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24T10:59:00Z'));
        $this->assertTrue($this->service()->state($this->consentKey)->granted(), 'still inside the allowed time');
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24T11:00:00Z'));
        $this->assertFalse($this->service()->state($this->consentKey)->granted(), 'the clock closes a consent after the allowed time (120 minutes by default)');
    }

    public function test_revoking_appends_a_row_and_never_rewrites_the_grant(): void
    {
        $this->consentTo([AssistantDataCategory::FormulaComposition]);
        $grant = AssistantConsentEvent::query()->sole();

        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24T09:30:00Z'));
        $this->service()->revoke($this->consentKey, AssistantConsentEndReason::User);

        $this->assertFalse($this->service()->state($this->consentKey)->granted());
        $rows = AssistantConsentEvent::query()->orderBy('occurred_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($grant->id, $rows[0]->id);
        $this->assertSame(AssistantConsentEventType::Granted, $rows[0]->event);
        $this->assertSame([AssistantConsentEventType::Revoked, AssistantConsentEndReason::User], [$rows[1]->event, $rows[1]->end_reason]);

        $this->service()->revoke($this->consentKey, AssistantConsentEndReason::User);
        $this->assertSame(2, AssistantConsentEvent::query()->count(), 'a session with no consent gets no empty revoke row');
    }

    public function test_the_logs_are_append_only_in_the_model_and_in_the_database(): void
    {
        $this->consentTo();
        $event = AssistantConsentEvent::query()->sole();
        $this->service()->disclosed($this->consentKey, 'x', [AssistantDataCategory::FormulaCost]);
        $disclosure = AssistantDisclosure::query()->sole();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $event->update(['session_key' => 'other']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $event->delete());
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $disclosure->update(['surface' => 'other']));
        foreach (['update assistant_consent_events set session_key = 1', 'delete from assistant_disclosures', 'truncate assistant_consent_events'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail("{$sql} must be refused by the database");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_stale_consent_gets_a_closing_row_once_from_the_scheduled_command(): void
    {
        $this->consentTo();
        $this->clock->advanceTo(CarbonImmutable::parse('2026-11-24T12:00:00Z'));

        $this->artisan('assistant:expire-consents')->assertSuccessful();
        $this->artisan('assistant:expire-consents')->assertSuccessful();

        $closing = AssistantConsentEvent::query()->where('event', 'revoked')->sole();
        $this->assertSame(AssistantConsentEndReason::Expired, $closing->end_reason);
        $this->assertSame('2026-11-24T12:00:00+00:00', $closing->occurred_at->toIso8601String());
    }

    public function test_the_session_key_is_a_hash_of_the_session_id(): void
    {
        $key = $this->service()->sessionKey('abc');

        $this->assertSame(64, strlen($key));
        $this->assertStringNotContainsString('abc', $key);
        $this->assertNotSame($key, $this->service()->sessionKey('abd'));
    }

    public function test_every_tool_declares_what_it_would_show_the_provider(): void
    {
        $expected = [
            'get_material' => [],
            'calculate_formula' => ['formula_composition', 'formula_cost'],
            'optimize_tier' => ['formula_composition', 'formula_cost'],
            'check_compliance' => ['formula_composition'],
            'convert_units' => [],
            'explain_result' => [],
            'extract_coa' => ['supplier_certificate'],
        ];

        $declared = [];
        foreach (app(AssistantToolRegistry::class)->all() as $name => $tool) {
            $declared[$name] = array_column($tool->dataCategories([]), 'value');
        }

        $this->assertSame($expected, $declared);
    }

    public function test_without_consent_a_formula_tool_is_refused_before_it_runs_and_the_model_is_told(): void
    {
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId])->replyText('محتاج موافقتك الأول.');

        $this->ask()->assertOk()->assertJson(['status' => 'answered']);

        $result = $this->firstToolResult();
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('بموافقتك', $result['content']);
        $this->assertStringContainsString('مكونات التركيبة', $result['content']);
        $this->assertStringNotContainsString('"value":"16"', $result['content'], 'the cost never reached the model');
        $log = AssistantDisclosure::query()->sole();
        $this->assertSame([AssistantDisclosureOutcome::Refused, 'calculate_formula', $this->consentKey], [$log->outcome, $log->surface, $log->session_key]);
        $this->assertEqualsCanonicalizing(['formula_composition', 'formula_cost'], $log->categories);
    }

    public function test_with_consent_the_tool_runs_and_the_categories_sent_are_logged(): void
    {
        $this->consentTo();
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId])->replyText('تكلفة الكيلو 16 جنيه.');

        $this->ask()->assertOk();

        $this->assertStringContainsString('"value":"16"', $this->firstToolResult()['content']);
        $log = AssistantDisclosure::query()->sole();
        $this->assertSame(AssistantDisclosureOutcome::Sent, $log->outcome);
        $this->assertEqualsCanonicalizing(['formula_composition', 'formula_cost'], $log->categories);
        $this->assertSame(['id', 'session_key', 'surface', 'categories', 'outcome', 'user_id', 'occurred_at'], array_keys($log->getAttributes()), 'the log holds categories and times, never content');
    }

    public function test_a_consent_for_one_category_does_not_open_another(): void
    {
        $this->consentTo([AssistantDataCategory::FormulaComposition]);
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId])->replyText('تمام');

        $this->ask()->assertOk();

        $this->assertTrue($this->firstToolResult()['is_error'], 'the cost needs its own consent');
        $this->assertSame(AssistantDisclosureOutcome::Refused, AssistantDisclosure::query()->sole()->outcome);
    }

    public function test_a_tool_over_public_reference_data_is_never_gated_or_logged(): void
    {
        $this->model->callTool('convert_units', ['value' => '1', 'from' => 'kg', 'to' => 'g'])->replyText('تمام');

        $this->ask('حوّل 1 كيلو لجرام')->assertOk();

        $this->assertArrayNotHasKey('is_error', $this->firstToolResult(), (string) ($this->firstToolResult()['content'] ?? ''));
        $this->assertSame(0, AssistantDisclosure::query()->count());
    }

    public function test_the_extract_coa_tool_needs_the_certificate_consent(): void
    {
        $this->consentTo([AssistantDataCategory::FormulaComposition, AssistantDataCategory::FormulaCost]);
        $this->model->callTool('extract_coa', ['extractionId' => fake()->uuid()])->replyText('تمام');

        $this->ask('إيه في الشهادة؟')->assertOk();

        $this->assertTrue($this->firstToolResult()['is_error']);
        $this->assertSame(['supplier_certificate'], AssistantDisclosure::query()->sole()->categories);
    }

    public function test_earlier_assistant_replies_are_not_sent_again_without_consent(): void
    {
        $history = [['role' => 'user', 'text' => 'كام التكلفة؟'], ['role' => 'assistant', 'text' => 'التكلفة 16 جنيه'], ['role' => 'user', 'text' => 'وبعدين؟'], ['role' => 'assistant', 'text' => 'الخامة A بـ 6']];

        $this->model->replyText('تمام');
        $this->ask('كمل', $history)->assertOk();
        $this->assertSame(['user', 'user', 'user'], array_column($this->model->payloads[0]['messages'], 'role'));
        $this->assertStringNotContainsString('16', (string) json_encode($this->model->payloads[0]['messages']));

        $this->consentTo();
        $this->model->replyText('تمام');
        $this->ask('كمل', $history)->assertOk();
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($this->model->payloads[1]['messages'], 'role'));
    }

    public function test_a_request_with_no_session_has_no_consent(): void
    {
        $this->runUnderConsentSession(null);
        $this->model->callTool('check_compliance', ['formulaVersionId' => $this->versionId])->replyText('تمام');

        $this->ask()->assertOk();

        $this->assertTrue($this->firstToolResult()['is_error']);
        $this->assertSame(AssistantConsentService::NO_SESSION, AssistantDisclosure::query()->sole()->session_key);
    }

    public function test_the_certificate_upload_is_refused_without_consent_and_nothing_is_stored_or_sent(): void
    {
        $this->modelReadsTheCertificate();
        $material = TradeMaterial::factory()->create();

        $response = $this->post(route('coa.store'), ['trade_material_id' => $material->id, 'file' => $this->pdf()]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('شهادات تحليل الموردين', (string) session('error'));
        $this->assertSame(0, $this->model->callCount(), 'nothing reached the provider');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, CoaExtraction::query()->count());
        $this->assertSame([AssistantDisclosureOutcome::Refused, 'coa_upload'], [AssistantDisclosure::query()->sole()->outcome, AssistantDisclosure::query()->sole()->surface]);
    }

    public function test_the_certificate_upload_with_consent_is_logged_as_sent(): void
    {
        $this->consentTo([AssistantDataCategory::SupplierCertificate]);
        $this->modelReadsTheCertificate();

        $this->post(route('coa.store'), ['trade_material_id' => TradeMaterial::factory()->create()->id, 'file' => $this->pdf()])->assertSessionHasNoErrors();

        $log = AssistantDisclosure::query()->sole();
        $this->assertSame([AssistantDisclosureOutcome::Sent, ['supplier_certificate']], [$log->outcome, $log->categories]);
        $this->assertSame(1, $this->model->callCount());
    }

    public function test_the_service_throws_a_translated_domain_exception_in_both_languages(): void
    {
        foreach (['ar' => 'بموافقتك', 'en' => 'unless you allow'] as $locale => $text) {
            app()->setLocale($locale);
            $this->assertThrowsException(AssistantException::class, fn () => app(CoaExtractionService::class)->extract($this->pdf(), TradeMaterial::factory()->create(), null, 'nobody'), $text);
        }
        app()->setLocale('ar');
    }

    public function test_the_consent_endpoints_validate_grant_read_and_revoke(): void
    {
        $this->getJson('/api/assistant/consent')->assertOk()->assertJson(['granted' => false, 'categories' => []]);

        $this->postJson('/api/assistant/consent', [])->assertStatus(422)->assertJsonValidationErrors('categories');
        $this->postJson('/api/assistant/consent', ['categories' => []])->assertStatus(422);
        $this->postJson('/api/assistant/consent', ['categories' => ['everything']])->assertStatus(422)->assertJsonValidationErrors('categories.0');

        $this->postJson('/api/assistant/consent', ['categories' => ['formula_cost', 'supplier_certificate']])
            ->assertOk()
            ->assertJson(['granted' => true, 'categories' => ['formula_cost', 'supplier_certificate'], 'grantedAt' => '2026-11-24T09:00:00+00:00', 'expiresAt' => '2026-11-24T11:00:00+00:00']);
        $this->getJson('/api/assistant/consent')->assertJson(['granted' => true]);

        $this->deleteJson('/api/assistant/consent')->assertOk()->assertJson(['granted' => false]);
        $this->assertSame(AssistantConsentEndReason::User, AssistantConsentEvent::query()->where('event', 'revoked')->sole()->end_reason);
    }

    public function test_the_consent_lives_in_the_real_browser_session_cookie(): void
    {
        $this->app->instance(AssistantSessionKey::class, new AssistantSessionKey($this->service()));
        $this->model->callTool('check_compliance', ['formulaVersionId' => $this->versionId])->replyText('تمام');

        $grant = $this->postJson('/api/assistant/consent', ['categories' => ['formula_composition']])->assertOk();
        $cookieName = config('session.cookie');
        $cookie = $grant->getCookie($cookieName);
        $this->assertNotNull($cookie, 'the consent endpoint starts the browser session');

        // The same session asks: allowed. A different session (no cookie) asks: refused.
        $this->withCredentials()->withCookie($cookieName, $cookie->getValue())->ask()->assertOk();
        $this->assertSame('sent', AssistantDisclosure::query()->sole()->outcome->value);

        $this->defaultCookies = [];
        $this->model->callTool('check_compliance', ['formulaVersionId' => $this->versionId])->replyText('تمام');
        $this->ask()->assertOk();
        $this->assertSame(['sent', 'refused'], AssistantDisclosure::query()->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($row) => $row->outcome->value)->all());
    }

    public function test_signing_out_ends_the_consent_of_that_session(): void
    {
        $this->consentTo();
        $request = Request::create('/');
        $session = app('session.store');
        $session->start();
        $request->setLaravelSession($session);
        $key = $this->service()->sessionKey($session->getId());
        $this->service()->grant($key, [AssistantDataCategory::FormulaCost]);

        (new RevokeAssistantConsentOnLogout($request, new AssistantSessionKey($this->service()), $this->service()))->handle(new Logout('web', User::factory()->create()));

        $this->assertFalse($this->service()->state($key)->granted());
        $this->assertSame(AssistantConsentEndReason::SessionEnded, AssistantConsentEvent::query()->where('session_key', $key)->where('event', 'revoked')->sole()->end_reason);
        $this->assertTrue($this->service()->state($this->consentKey)->granted(), 'another session is untouched');
    }

    public function test_the_assistant_is_a_404_while_the_master_switch_is_off(): void
    {
        config(['assistant.enabled' => false]);

        $this->postJson('/api/assistant/ask', ['question' => 'x'])->assertNotFound();
        $this->getJson('/api/assistant/consent')->assertNotFound();
        $this->postJson('/api/assistant/consent', ['categories' => ['formula_cost']])->assertNotFound();
        $this->assertSame(0, $this->model->callCount());
    }

    public function test_the_master_switch_defaults_to_off_in_production_and_on_elsewhere(): void
    {
        $original = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_ENV['ASSISTANT_ENABLED'] = $_SERVER['ASSISTANT_ENABLED'] = null;
        unset($_ENV['ASSISTANT_ENABLED'], $_SERVER['ASSISTANT_ENABLED']);
        putenv('ASSISTANT_ENABLED');

        try {
            foreach (['production' => false, 'local' => true, 'testing' => true] as $environment => $expected) {
                $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;
                putenv("APP_ENV={$environment}");
                $this->assertSame($expected, (require config_path('assistant.php'))['enabled'], $environment);
            }
        } finally {
            $_ENV['APP_ENV'] = $original[0];
            $_SERVER['APP_ENV'] = $original[1];
            putenv('APP_ENV='.($original[2] === false ? 'testing' : $original[2]));
        }
    }

    public function test_the_pages_share_the_panel_settings_and_the_categories_to_ask_about(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('assistant.enabled', true)
            ->where('assistant.consentUrl', '/api/assistant/consent')
            ->where('assistant.categories.0.value', 'formula_composition')
            ->has('assistant.categories', 3));

        config(['assistant.enabled' => false]);
        $this->get('/')->assertInertia(fn ($page) => $page->where('assistant.enabled', false));
    }
}
