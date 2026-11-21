/**
 * Message keys the optimizer can emit. The optimizer returns ONLY keys + params (never display text);
 * all UI text lives in the app's lang files (apps/api/lang/{ar,en}/optimizer.php, nested by the dotted key
 * after `optimizer.`, Laravel `:param` placeholders). This typed list is the contract the app verifies:
 * `messages.keys.json` is generated from it (messages.test.ts fails when stale) and a PHPUnit test checks
 * every key exists in both locales.
 *
 * Warnings are strings `key` or `key:detail` (detail fills the `detail` param). Keys that come from the
 * engine itself (`input.*`, `confidence.*`) are the engine's, not listed here.
 * `params` lists the placeholders the text may use; an emitter may supply extra params.
 */
export interface MessageKeySpec {
  key: string;
  params: readonly string[];
}

const k = (key: string, ...params: string[]): MessageKeySpec => ({ key, params });

export const OPTIMIZER_MESSAGE_KEYS: readonly MessageKeySpec[] = [
  // conflicts (iis.ts / build.ts)
  k('optimizer.infeasible.target_price_too_low', 'limitEGPperKg', 'minAchievableEGPperKg', 'gapEGPperKg'),
  k('optimizer.infeasible.max_cost_too_low', 'limitEGPperKg', 'minAchievableEGPperKg', 'gapEGPperKg'),
  k('optimizer.infeasible.cost_limit_conflicts', 'limitEGPperKg'),
  k('optimizer.infeasible.active_min_too_high', 'limitPct'),
  k('optimizer.infeasible.active_max_too_low', 'limitPct'),
  k('optimizer.infeasible.function_min_too_high', 'function', 'limitPct'),
  k('optimizer.infeasible.function_max_too_low', 'function', 'limitPct'),
  k('optimizer.infeasible.material_min_too_high', 'materialId', 'limitPct'),
  k('optimizer.infeasible.material_max_too_low', 'materialId', 'limitPct'),
  k('optimizer.infeasible.structural'),
  // errors
  k('optimizer.error.verification_failed', 'id', 'slack'),
  k('optimizer.error.solver_status', 'status'),
  k('optimizer.input.duplicate_material', 'materialId'),
  // warnings
  k('optimizer.warning.rounding_margin_applied'),
  k('optimizer.warning.solver_precision_fallback'),
  k('optimizer.warning.measured_requirements_not_enforced', 'detail'),
  k('optimizer.warning.function_bound_unset', 'detail'),
  k('optimizer.warning.scenario_invalid', 'detail'),
  // missing[] reasons
  k('optimizer.no_usable_materials'),
  k('optimizer.required_material_not_offered'),
  k('optimizer.required_material_needs_min'),
  k('optimizer.bounded_material_not_offered'),
  k('optimizer.qs_material_not_usable'),
  k('optimizer.input.invalid_function_tags'),
  k('optimizer.reverse.non_positive_price'),
  k('optimizer.reverse.invalid_margin_basis'),
  // profile validation (path in params.path)
  ...[
    'unknown_field', 'field_required', 'not_decimal_string', 'out_of_range', 'not_array', 'not_object', 'not_id',
    'duplicate', 'min_exceeds_max', 'required_and_forbidden', 'source_required', 'invalid_tier', 'invalid_price_unit',
    'field_null_required', 'function_not_declared', 'unknown_required_field',
  ].map((n) => k(`optimizer.profile.${n}`, 'path', 'detail')),
  // reverse pricing steps
  ...[
    'shelf_price_incl_vat', 'price_ex_vat', 'retailer_purchase_price', 'wholesaler_purchase_price',
    'factory_price', 'max_unit_cost', 'max_cost_per_kg', 'max_formula_cost_per_kg',
  ].map((n) => k(`optimizer.reverse.step.${n}`, 'value')),
];

/** Contents of messages.keys.json. */
export function messageKeysJson(): string {
  const body = { keys: OPTIMIZER_MESSAGE_KEYS.map((s) => s.key), params: Object.fromEntries(OPTIMIZER_MESSAGE_KEYS.map((s) => [s.key, [...s.params]])) };
  return `${JSON.stringify(body, null, 2)}\n`;
}
