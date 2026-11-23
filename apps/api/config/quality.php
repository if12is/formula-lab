<?php

use App\Models\ComplianceRule;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use App\Models\IfraCertificate;
use App\Models\LabProtocol;
use App\Models\LabTrial;
use App\Models\MaterialComposition;
use App\Models\PackagingCompatibility;
use App\Models\Price;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\ProductionDeviation;
use App\Models\ProductionOrder;
use App\Models\PropertyValue;
use App\Models\RuleSet;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TierProfile;
use App\Models\TradeMaterial;

/*
| Y-07: every table that holds quality evidence, and how it is protected. A table is either
|   append_only : a PostgreSQL trigger (fl_reject_modification) refuses UPDATE, DELETE and TRUNCATE; a change is a new row; or
|   audited     : its model uses App\Concerns\Auditable, so every create/update/delete writes who, when, before, after and why
|                 into audit_log (itself append-only).
| `outside` lists the tables that are not quality evidence, with the reason. Tests/Feature/Quality/QualityTablesTest.php fails
| for any table in the database that is in none of the three lists, so a new table cannot be forgotten.
*/
return [
    'append_only' => [
        'audit_log',
        'calc_runs',
        'dataset_versions',
        'decision_overrides',
        'export_logs',
        'formula_cost_approvals',
        'formula_publications',
        'formula_version_provenances',
        'formula_version_transitions',
        'lab_measurements',
        'production_batch_checks',
        'terms_acceptances',
        'tested_value_marks',
    ],

    /* table => model class that carries the Auditable trait. */
    'audited' => [
        'formulas' => Formula::class,
        'formula_versions' => FormulaVersion::class,
        'formula_lines' => FormulaLine::class,
        'formula_process_steps' => FormulaProcessStep::class,
        'formula_process_checks' => FormulaProcessCheck::class,
        'production_orders' => ProductionOrder::class,
        'production_batches' => ProductionBatch::class,
        'production_batch_lines' => ProductionBatchLine::class,
        'production_deviations' => ProductionDeviation::class,
        'lab_trials' => LabTrial::class,
        'lab_protocols' => LabProtocol::class,
        'rule_sets' => RuleSet::class,
        'rules' => ComplianceRule::class,
        'ifra_certificates' => IfraCertificate::class,
        'packaging_compatibilities' => PackagingCompatibility::class,
        'material_compositions' => MaterialComposition::class,
        'property_values' => PropertyValue::class,
        'substances' => Substance::class,
        'trade_materials' => TradeMaterial::class,
        'sources' => Source::class,
        'prices' => Price::class,
        'tier_profiles' => TierProfile::class,
        'eda_checklist_entries' => EdaChecklistEntry::class,
    ],

    'outside' => [
        'users' => 'accounts, not quality evidence (RBAC and login are U-01)',
        'password_reset_tokens' => 'framework',
        'sessions' => 'framework',
        'cache' => 'framework',
        'cache_locks' => 'framework',
        'jobs' => 'framework',
        'job_batches' => 'framework',
        'failed_jobs' => 'framework',
        'tenants' => 'account structure',
        'idempotency_keys' => 'transport bookkeeping',
        'product_families' => 'reference registry loaded from a versioned data file (packages/engine/data)',
        'formula_templates' => 'reference registry loaded from a versioned data file (packages/engine/data)',
        'tax_regimes' => 'reference data loaded from a seeder; the sources it cites are audited',
        'exchange_rates' => 'commercial rate, audited through its model; not a quality record',
        'price_lists' => 'commercial, audited through its model; the trade itself lives in Sanad',
        'price_list_items' => 'commercial, audited through its model; the trade itself lives in Sanad',
        'price_list_customers' => 'commercial assignment that lives in Sanad',
        'sanad_refs' => 'Sanad integration cache',
        'sanad_products_cache' => 'Sanad integration cache',
        'sanad_suppliers_cache' => 'Sanad integration cache',
        'sanad_customers_cache' => 'Sanad integration cache',
        'sanad_purchase_lines' => 'Sanad integration cache',
        'sanad_devices' => 'Sanad integration link',
        'sanad_outbox' => 'Sanad integration queue',
        'assistant_requests' => 'usage log of the assistant (tokens, cost, status); holds no formula, question or reply',
        'migrations' => 'framework',
    ],
];
