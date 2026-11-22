/**
 * The one place that says which database and server settings the end-to-end run uses. The Playwright config hands them to the
 * Laravel server and the global setup hands them to artisan, so both always talk to the same, disposable database.
 */
export const E2E_DATABASE = 'formula_lab_e2e';

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

/** Environment for every artisan process and for the server: the process env wins over apps/api/.env. */
export const e2eServerEnv = (): Record<string, string> => ({
  DB_DATABASE: E2E_DATABASE,
  PRICING_RULES: E2E_PRICING_RULES,
  // Y-04 TEST-FIXTURE role: the owner has not named the roles that may override a blocked decision yet (HUMAN-TODO).
  DECISIONS_OVERRIDE_ROLES: E2E_OVERRIDE_ROLE,
});

/** Demo seeders every run starts from (each is idempotent and the specs still call the ones they need). */
export const E2E_DEMO_SEEDERS = ['FormulationDemoSeeder', 'ComplianceDemoSeeder', 'FamilyDemoSeeder', 'LabDemoSeeder', 'CostingDemoSeeder', 'PriceListDemoSeeder'];
