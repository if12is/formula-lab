/**
 * The one place that says which database and server settings the end-to-end run uses. The Playwright config hands them to the
 * Laravel server and the global setup hands them to artisan, so both always talk to the same, disposable database.
 */
/** `E2E_DATABASE` / `E2E_PORT` let two runs side by side use their own disposable database and server; the name must still end in `_e2e`. */
export const E2E_DATABASE = process.env.E2E_DATABASE ?? 'formula_lab_e2e';
export const E2E_PORT = process.env.E2E_PORT ?? '8000';
/** A second server on the next port with the terms-of-use gate ON (S-16): the main server runs with it off so the other specs are not sent to the terms screen. */
export const E2E_GATE_PORT = String(Number(E2E_PORT) + 1);
/** A scripted stand-in for the model provider (e2e/support/anthropic-stub.mjs): the assistant specs read back what the app sent it. */
export const E2E_PROVIDER_PORT = String(Number(E2E_PORT) + 2);

/**
 * TEST-FIXTURE margin rule (the app ships none: config/pricing.php). +25 % on cost, labelled "تجار الجملة", which the
 * price-list specs hand-compute against (unit cost 32.00 => 40.00). The source text says so: it is not business data.
 */
export const E2E_PRICING_RULES = JSON.stringify({
  merchants: {
    label: { ar: 'تجار الجملة', en: 'Wholesale merchants' },
    basis: 'markup_on_cost',
    margin_pct: '25',
    distribution_cost: null,
    source: 'قيمة اختبار لعرض الشاشة',
    confidence: 'L6_community',
    valid_from: '2026-01-01',
  },
});

/** TEST-FIXTURE role allowed to override a blocked decision in the e2e run. */
export const E2E_OVERRIDE_ROLE = 'quality_manager';

/**
 * TEST-FIXTURE production settings (phase Q). The owner has not decided the deviation threshold or the roles that approve a production order or
 * a deviation (HUMAN-TODO): the e2e run uses 5 % and the quality manager role so the screens can be exercised; the app ships neither.
 */
export const E2E_DEVIATION_THRESHOLD_PCT = '5';

/** TEST-FIXTURE role of an operator: weights only, no prices (central field policy, apps/api/config/field_visibility.php). */
export const E2E_OPERATOR_ROLE = 'production';

/** TEST-FIXTURE price age limit (the owner has not decided it: HUMAN-TODO S-15). The app ships none; the e2e run uses 30 days so the stale-price figure can be exercised. */
export const E2E_STALE_PRICE_DAYS = '30';

/** TEST-FIXTURE checklist data for the EDA file assistant (N-08): invented document names, because the shipped file lists none (the sources do not state them). */
export const E2E_EDA_DEFINITIONS = `${process.cwd()}/apps/api/tests/fixtures/eda-checklist.json`;

/** TEST-FIXTURE master key (U-02): a throwaway value for the disposable e2e database only; real environments generate their own (`php artisan crypto:generate-master-key`). */
export const E2E_MASTER_KEY = 'base64:' + Buffer.from('e2e-only-master-key-32-bytes!!!!').toString('base64');

/** Environment for every artisan process and for the server: the process env wins over apps/api/.env. */
export const e2eServerEnv = (): Record<string, string> => ({
  DB_DATABASE: E2E_DATABASE,
  APP_MASTER_KEY: E2E_MASTER_KEY,
  PRICING_RULES: E2E_PRICING_RULES,
  // Y-04 TEST-FIXTURE role: the owner has not named the roles that may override a blocked decision yet (HUMAN-TODO).
  DECISIONS_OVERRIDE_ROLES: E2E_OVERRIDE_ROLE,
  PRODUCTION_DEVIATION_THRESHOLD_PCT: E2E_DEVIATION_THRESHOLD_PCT,
  PRODUCTION_APPROVER_ROLES: E2E_OVERRIDE_ROLE,
  PRODUCTION_WEIGHTS_ONLY_ROLES: E2E_OPERATOR_ROLE,
  DASHBOARD_STALE_PRICE_DAYS: E2E_STALE_PRICE_DAYS,
  // The home lists are capped (5) and ordered by code: with every spec adding products to the shared database, a fixed product can fall off the first five. The e2e run lifts the cap.
  DASHBOARD_LIST_LIMIT: '200',
  EDA_CHECKLIST_DEFINITIONS_PATH: E2E_EDA_DEFINITIONS,
  // S-16: off on the main server; the gate server (E2E_GATE_PORT) turns it on.
  TERMS_GATE_ENFORCED: 'false',
  // P-08/P-10: the assistant talks to the scripted stand-in, with a placeholder key (no real key exists, and none is ever read here).
  ANTHROPIC_BASE_URL: `http://127.0.0.1:${E2E_PROVIDER_PORT}`,
  ANTHROPIC_API_KEY: 'e2e-placeholder-key',
  ASSISTANT_ENABLED: 'true',
  // The screenshot specs ask many questions from one IP inside a minute; the shipped limit (20 a minute) stays the default.
  ASSISTANT_RATE_LIMIT_PER_MINUTE: '300',
});

/** Demo seeders every run starts from (each is idempotent and the specs still call the ones they need). */
export const E2E_DEMO_SEEDERS = ['FormulationDemoSeeder', 'ComplianceDemoSeeder', 'FamilyDemoSeeder', 'LabDemoSeeder', 'CostingDemoSeeder', 'PriceListDemoSeeder', 'ProductionDemoSeeder', 'TiersDemoSeeder'];
