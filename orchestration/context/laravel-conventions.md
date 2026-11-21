# Laravel conventions (owner's house style — from wasef201/sanad + if12is/sanad-central; sanad-central is the reference)

## Layering (mandatory)
Route (named, grouped) → FormRequest (`app/Http/Requests/<Area>/<Verb><Noun>Request.php`) → thin Controller → `final` Service (`app/Services/<Area>/XService.php`, orchestrates, owns DB::transaction when combining actions, throws domain exceptions) → `final` Action (`app/Actions/<Area>/<Verb><Noun>Action.php`, single public `__invoke(...)`, one atomic thing, `@throws` PHPDoc, may wrap multi-row writes in DB::transaction) → Model.
- Controllers: inject the Service by constructor promotion, type-hint the FormRequest, use `$request->validated()`, catch domain exceptions → `back()->withErrors([...])`, success → `redirect()->route(...)->with('success', __('<domain>.<key>'))`. Return `Inertia\Response as InertiaResponse` or `RedirectResponse`. NEVER inline `$request->validate()`, never business logic.
- One Service owns all writes to a table (single write path).
- Domain exceptions: `app/Exceptions/Domain/*Exception.php` extending a shared `BusinessRuleViolationException`, messages from `lang/*/exceptions.php`.
- DTOs only for result objects: `app/Support/DTOs/`, `final readonly class` with `toArray()`.

## Enums
`app/Enums/` (domain subfolders allowed), string-backed, TitleCase cases, snake_case values. `label(): string` → `__('enums.<enum_snake>.'.$this->value)`; static `options(): array` → `[{value,label}]` for selects; static `values()`. Cast with the enum class, validate with `Rule::enum(...)`, query with enum cases.

## Form Requests
Array rule syntax, `@return array<string, mixed>`, `use TranslatesValidationMessages;` (attributes() from `__('validation.attributes')`, messages() from lang). `authorize()` does permission checks (`$this->user()?->can('formulas.manage')`) once RBAC exists (U-01); until then `true` behind auth middleware. `prepareForValidation` / `withValidator` for normalisation and cross-field rules.

## Translations
`lang/{ar,en}/*.php` with identical key sets (test enforces): common, validation (+attributes), exceptions, enums, one file per domain. snake_case keys, `__('domain.key')`. Never hard-coded Arabic/English in PHP or Vue. Frontend reads the same PHP lang groups shared via Inertia (Formula Lab's single-source choice; no duplicated JSON files).

## Models
Explicit `$fillable`, `protected function casts(): array`, typed relations with imported relation classes, `/** @use HasFactory<XFactory> */`, factory for every model, `Model::query()->...`, state-named scopes.

## Inertia
`Inertia::render('Area/Page', [...])` with camelCase props, `filters` echoed back, paginate `->withQueryString()`, arrays shaped in controller/service (`->through(fn (X $x): array => ...)`), no API Resources for Inertia (Resources only for JSON APIs). Flash `{success,error,warning}` shared lazily in HandleInertiaRequests and rendered as toasts.

## Authorization
Permission strings checked in FormRequest::authorize() + `Gate::before` super-admin bypass; Policies only if a model-level need appears.

## Tests
PHPUnit classes (NOT Pest): `tests/Feature/...Test.php`, `extends Tests\TestCase`, `use RefreshDatabase`, `public function test_snake_case(): void`, factories, happy + failure + edge paths; each Action has a test. Run `php artisan test --compact --filter=...`.

## Style
`vendor/bin/pint --dirty`; curly braces always; constructor promotion; full param/return types; PHPDoc array shapes; `final` Services/Actions, non-final Controllers/Models/Requests; no `declare(strict_types=1)`; class constants for magic values; config keys instead of hard-coded values, no `env()` outside config.
