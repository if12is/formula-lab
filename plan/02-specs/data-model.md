# نموذج الداتا (Data Model) — D-01

المرجع التنفيذي: migrations في `apps/api/database/migrations/2026_11_21_*`. الـ SQL المختصر القديم: `data-model.sql` (تجريدي، الأولوية للـ migrations لو اختلفوا).

## قواعد عامة
- **الملكية:** Formula Lab بيملك التركيبي/العلمي/التكلفة المحسوبة. التجاري والتشغيلي (موردين، مشتريات، مخزون، مبيعات، موزعين) = Sanad، وعندنا بس `sanad_refs` وكاش `sanad_purchase_lines` (راجع `sanad-integration.md`). مفيش جدول موردين/مشتريات/مخزون هنا.
- **UUID** مفاتيح أساسية (UUIDv7) ماعدا `dataset_versions` (sha256) و`audit_log` (bigserial لترتيب الإضافة). `users` لسه بـ bigint فـ `created_by`/`actor_id` = bigint.
- **أرقام:** `numeric` من غير scale (دقيق، من غير تقريب)، بتتقرا في PHP كـ string بـ cast `DecimalString` (الـ float مرفوض). الـ hash بيشتغل على شكل موحّد للعشري (`DecimalNormalizer`: `1.50` ≡ `1.5`).
- **مصدر وثقة:** `property_values` و`prices` ليهم `source_id` NOT NULL + `confidence` (L1–L7) بـ check constraint. قيمة ناقصة تفضل NULL (مش صفر) وتتسجل في `HUMAN-TODO.md`.
- **Tenancy (U-04 هيكمّلها):** جدول `tenants` + عمود `tenant_id` على كل جدول مملوك لمستأجر. الداتا المرجعية العامة (`sources`, `property_values`, `prices`, `trade_materials`) فيها `tenant_id` **nullable** (NULL = مرجع عام). `formulas` و`calc_runs` تبع مستأجر. مفيش global scope لسه.
- **Immutability (ADR 003):** `audit_log` و`dataset_versions` append-only بـ trigger. نسخة التركيبة المعتمدة (`approved`) وسطورها immutable بـ trigger + guard في الـ model.

## ER مبسّط
```
tenants 1─* formulas 1─* formula_versions 1─* formula_lines *─1 trade_materials *─1 substances
                          └─ parent_version_id (self)               │  └─ sanad_product_uuid / sanad_supplier_uuid (soft refs → Sanad)
sources 1─* property_values (entity_type+entity_id → substances | trade_materials)
sources 1─* prices *─1 trade_materials
dataset_versions 1─* calc_runs
audit_log  (يسجل تعديلات substances, trade_materials, sources, property_values, prices)
```

## الجداول

### منفّذة (D-02 → D-10)
| الجدول | الغرض | الملكية |
| --- | --- | --- |
| `tenants` | المستأجر (مصنع). التفاصيل في U-04. | FL |
| `substances` | مادة كيميائية: `cas` (unique لما يكون موجود), `inci`, `name_en/ar`, `mw`, `formula`. | FL مرجعي |
| `trade_materials` | درجة تجارية (مثلاً "SLES 70%"). `substance_id` NULL للخلائط (`is_mixture`) أو المسودة. `status` draft/active. `sanad_product_uuid` و`sanad_supplier_uuid` ربط ناعم من غير FK. مادة واحدة ليها درجات كتير. | FL (الصنف التجاري نفسه في Sanad) |
| `sources` | مصدر داتا: `kind`, `title`, `url`, `license`, `retrieved_at`. | FL مرجعي |
| `property_values` | أي خاصية لـ substance/trade_material: `value` **أو** `min`/`max` (check: واحد على الأقل موجود، و`min<=max`), `unit`, `source_id`, `confidence`, `valid_from`, `lot_no` (ربط COA بدفعة Sanad). التصحيح = صف جديد بـ `valid_from` جديد. | FL مرجعي |
| `prices` | **عروض أسعار مرجعية فقط** (`amount`, `currency`, `per_unit`, `quoted_on`, `fx_rate`, `fx_source` + مصدر/ثقة). سعر الشراء الفعلي من `sanad_purchase_lines`. | FL مرجعي |
| `audit_log` | append-only: `table_name`, `record_id`, `action`, `before`/`after` (jsonb, للتعديل الحقول المتغيرة بس), `actor_id`, `reason`, `at` (ساعة السيرفر). trigger بيرفض UPDATE/DELETE/TRUNCATE. | FL |
| `dataset_versions` | snapshot للداتا المرجعية: `id` = sha256 للـ canonical JSON، `manifest` (schema, scope, counts). immutable بـ trigger. | FL |
| `calc_runs` | سجل كل حساب: `input_hash`, `output_hash`, `engine_version`, `dataset_version` (FK). | FL |
| `formulas` | التركيبة (الهوية): `tenant_id`, `code` (unique per tenant), `name`, `product_type`, `tier` اختياري (economy/mid/premium). | FL |
| `formula_versions` | نسخة: `version` (unique per formula), `hash`, `status` (draft/lab_testing/approved/rejected), `reason` (إجباري من النسخة 2), `parent_version_id`, `created_by`. كل تعديل = صف جديد. | FL |
| `formula_lines` | سطور النسخة: `line_no`, `trade_material_id`, `percent_ww` (دقيق، NULL لسطر q.s. بس), `is_qs`, `phase`. | FL |

**قرار السطور:** جدول `formula_lines` (قابل للاستعلام: "فين استُخدمت الخامة دي؟") بدل jsonb. مفيش snapshot jsonb مكرر (مصدر حقيقة واحد): الـ hash = SHA-256 للـ canonical JSON لقائمة السطور (`FormulaLine::canonical()`: `lineNo, tradeMaterialId, percentWw, isQs, phase` كلها strings/bool/null). `FormulaVersion::verifyHash()` بيعيد البناء من الجدول ويقارن.

**Canonical JSON:** نفس قواعد `packages/engine/src/hash.ts` (مفاتيح مرتبة بترتيب UTF-16، من غير مسافات، الأرقام ممنوعة ⇒ strings). متطبق في `App\Support\CanonicalJson` وبيتقارن مع المحرك الحقيقي بـ test عبر `node` (`tests/Feature/Data/DatasetVersionTest.php`).

### مخطط لها (مراحل لاحقة، لسه من غير migrations)
| الجدول | الغرض | الملكية / المرحلة |
| --- | --- | --- |
| `lab_results` | قياسات المعمل على نسخة تركيبة (`formula_version_id`, property, value, unit, method, operator). | FL — حلقة المعمل |
| `tiers` / `tier_profiles` | قيود الفئة (اقتصادي/متوسط/فاخر) لكل `product_type` (شكل JSON في `tier-profiles.md`). كل رقم من مصدر وإلا `null`. | FL — J-02 |
| `rules` | قواعد المطابقة (DSL في `rules-dsl.md`) + `source_id`. | FL — N |
| `sanad_refs` | ربط `entity_type` + `sanad_uuid` بالصف المحلي. | **كاش/ربط**، Sanad هو المالك — مرحلة التكامل |
| `sanad_purchase_lines` | إسقاط للقراءة فقط لبنود مشتريات Sanad لحساب التكلفة الواصلة؛ بيتعاد بناؤه، Sanad يكسب في أي تعارض. | **كاش**، Sanad المالك |
| (باقي `data-model.sql`: items, skus, packaging_boms, landed_cost_charges, price_waterfalls…) | تكلفة/تغليف. | FL — مراحل التكلفة |

## نقاط خارج النطاق دلوقتي
- **Sources diff** في `FormulaDiffService` (سطور خاماتها اتغيرت مصادر خواصها المختارة) مؤجل لحد قاعدة اختيار القيمة D-04.
- Global scope للـ tenant و policies = U-04.
