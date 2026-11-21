# عقد المحرك (Engine Contract)

```ts
type Confidence = 'L1_coa_batch'|'L2_supplier_tds'|'L3_standard'|'L4_regulatory_db'|'L5_reference'|'L6_community'|'L7_estimate';

interface SourcedValue { value: string /* decimal as string */; unit: Unit; min?: string; max?: string;
  sourceId: string; confidence: Confidence; validFrom: string /* ISO date */; }

interface EngineInput {
  engineVersion: string;          // semver, pinned
  datasetVersion: string;         // hash of reference data snapshot
  formula: { lines: { materialId: string; percentWW: string }[]; productDensity?: SourcedValue };
  batch?: { size: Quantity /* kg or L */; wastagePct: string; packSize: Quantity; packCost: string };
  prices: Record<string /*materialId*/, SourcedValue /* EGP/kg dated */>;
  policy: { allowConfidenceBelow?: Confidence /* default L6 */; rounding: 'default' };
}

interface EngineOutput {
  ok: boolean;
  missing: { materialId?: string; property: string; reason: string }[];  // non-empty => ok=false
  results: Record<string, { value: string; unit: Unit; trace: TraceNode }>;
  warnings: string[];
  inputHash: string; outputHash: string; engineVersion: string; datasetVersion: string;
}
```

## قواعد
- المحرك pure: مفيش IO، مفيش Date.now، مفيش random. الداتا بتدخل كلها في الـ input.
- الأرقام strings داخل الـ JSON (عشان مفيش float في النقل).
- `inputHash` = SHA-256 لـ canonical JSON للمدخلات ⇒ نفس الـ hash = نفس الناتج حرفيًا.
- أي قيمة confidence أقل من `allowConfidenceBelow` ⇒ تحذير، و L7 ⇒ missing إلا لو فيه override موقّع.

## الإصدارات (Versioning)
الكود الفعلي للعقد: [`packages/engine/src/contract.ts`](../../packages/engine/src/contract.ts) والـ hashing في [`packages/engine/src/hash.ts`](../../packages/engine/src/hash.ts) (canonical JSON + SHA-256 عبر `@noble/hashes`، أي `number` في الـ payload بيرمي error).

- `ENGINE_VERSION` semver:
  - **patch** = مفيش أي تغيير في أي ناتج (إصلاح داخلي، أداء، refactor).
  - **minor** = حقول جديدة بس (additive)، والنواتج الموجودة زي ما هي حرفيًا.
  - **major** = أي تغيير في أي ناتج رقمي (قاعدة حساب، تقريب، ترتيب، صيغة hash).
- كل `EngineOutput` بيحمل `engineVersion` و`datasetVersion` (ADR 003) والاتنين داخلين في `inputHash`.
- النتايج المخزّنة بتحتفظ بالـ `engineVersion` اللي طلّعها ومبتتحسبش تاني بصمت.
