# الربط مع Sanad POS

> G-01/G-02 — اتراجع على كود `wasef201/sanad` (commit `ff39eb2`، v1.2.68، 2026-11-21). كل claim تحت ليه مرجع `file:line` في ريبو sanad.
> العقد التفصيلي (JSON/headers/errors): `orchestration/context/sanad-contract-v1.md`.

## المبدأ
Sanad = نظام التجارة (POS، مشتريات، مخزون، مبيعات، عملاء، موزعين). Formula Lab = التركيبات والتكلفة والمطابقة والمعمل.
ما فيش جدول في Formula Lab بيكرر جدول تجاري في Sanad. الربط بـ `uuid` بتاع Sanad (جدول `sanad_refs`، شوف `data-model.sql`).

## Source of truth (نهائي)
القاعدة: **لو الكيان تجاري/تشغيلي ⇒ Sanad مالكه. لو تركيبي/علمي/تكلفة محسوبة ⇒ Formula Lab مالكه.** اللي بيتخزن عندنا من كيانات Sanad = `sanad_refs` (ربط) + `sanad_purchase_lines` (كاش للقراءة فقط لحساب التكلفة الواصلة، مش مصدر حقيقة وبيتعاد بناؤه من Sanad في أي وقت).

| الكيان | المالك | في Formula Lab | اتجاه الكتابة |
| --- | --- | --- | --- |
| الموردين + كشف الحساب (`Supplier`, `SupplierLedgerEntry`) | Sanad | `sanad_refs(entity_type='supplier')` + `trade_materials.sanad_supplier_uuid` | Sanad → FL (قراءة) |
| المشتريات وبنودها ومدفوعاتها (`Purchase`, `PurchaseItem`) | Sanad | كاش `sanad_purchase_lines` (read-only projection) | Sanad → FL |
| الخامات/الفوارغ/الكراتين كأصناف (`Product` بـ `item_kind` = raw_material/packaging) + وحداتها (`ProductUnit`, `Uom`) | Sanad | `items` (بيانات فنية/كيميائية) + `sanad_refs(entity_type='product')` | الصنف التجاري Sanad؛ الخواص الفنية FL |
| الدفعات `ProductBatch` (رقم الدفعة، الصلاحية، الرصيد) | Sanad | `sanad_refs(entity_type='batch')`؛ COA بيتربط بالدفعة بـ `property_values.lot_no` | Sanad → FL |
| المخزون وحركاته والجرد والتحويلات (`StockMovement`, …) | Sanad | ولا حاجة (قراءة أرصدة بس) | FL → Sanad عن طريق consume/receive فقط |
| تنفيذ الإنتاج على المخزون (صرف/استلام) | Sanad | أمر الإنتاج المعتمد (BOM) هو اللي بيتبعت | FL → Sanad |
| المنتج التام كصنف قابل للبيع (`Product` بـ `finished_good`) | Sanad | `skus` + `sanad_refs(entity_type='product')` | FL → Sanad (upsert: SKU، باركود، وحدة، تكلفة، سعر) |
| أسعار البيع/الجملة/الموزعين (`Product.sell_price/wholesale_price/guide_price`، `WholesaleTier`) | Sanad | سلّم السعر المقترح `price_waterfalls` (مقترح بس) | FL → Sanad (اقتراح؛ Sanad هو اللي بيطبّق) |
| العملاء والمبيعات والمرتجعات والفواتير والكاشير والفروع | Sanad | قراءة للتقارير فقط | — |
| التركيبات ونسخها واعتمادها، المحرك، الحاسبات | Formula Lab | — | — |
| داتا الخامات الكيميائية، COA، المصادر ودرجات الثقة | Formula Lab | — | — |
| تكلفة التركيبة والعبوة، التحويل (`cost_centers`)، الضرايب (`tax_regimes`) | Formula Lab | — | — |
| التكلفة الواصلة (`landed_cost_charges`: شحن/جمارك/تخليص/تمويل) | Formula Lab | مربوطة بـ `sanad_purchase_uuid` | — |
| الفئات والمُحسِّن، المطابقة (ES/CosIng/IFRA)، المعمل، الذكاء الاصطناعي | Formula Lab | — | — |
| التكلفة الفعلية للدفعة مقابل المحسوبة (مرحلة Q) | Formula Lab | بيقرا استهلاك/تكلفة فعلية من Sanad | Sanad → FL |

## اللي موجود في Sanad النهارده (متحقق منه في الكود)
| الموجود | الحقيقة | مرجع |
| --- | --- | --- |
| `/api/v1/store/*` (products, stock, orders…) | **للبوابة (Portal/MySanad) بس.** `ResolveStoreTenant` بيعمل `abort(404)` لو `PortalMode` مش مفعّل، يعني على Sanad Desktop (جهاز المصنع) بيرجع 404. بيحتاج `X-Sanad-Activation` + Bearer من `Setting store_api_token`. ممنوع نعتمد عليه. | `routes/api.php:11-18`، `app/Http/Middleware/ResolveStoreTenant.php:14-33`، `VerifyStoreApiToken.php:13-21`، `app/Services/Portal/PortalMode.php:12-18` |
| `/api/mobile/{products,suppliers,purchases,sales,…}` | Sanctum (`auth:sanctum`) + جهاز مسجّل (`X-Device-Uuid`، `mobile.device`). Paginator عادي (`per_page`) بدون cursor ولا `updated_since`، بيرجع `{success, <key>: paginator}`. مش مناسب كـ feed تغييرات (مفيش tombstones ولا ترتيب مستقر)، وبنود الشراء من غير وحدات/دفعات كاملة. | `routes/api.php:36-50`، `Api/Mobile/MobileApiController.php:114-203`، `EnsureMobileDeviceActive.php:19-24` |
| `/api/v1/sync/status`, `/sync/trigger`, `/online-orders/pending` | Bearer ثابت (`config('sanad.api_token')`، مقارنة `!==`). بتتحكم في مزامنة Sanad↔Central، مش بتنقل بيانات. | `routes/api.php:20-23`، `Http/Middleware/VerifyApiToken.php:13-17`، `Api/SyncApiController.php:13,43` |
| المزامنة الحالية | **على مستوى الداتابيز (DB-to-DB)** بين SQLite المحلي وMySQL المركزي عن طريق `SyncService`، مش HTTP API. فيه `sync_outbox` (`table_name,row_uuid,op,queued_at,attempts`) و`devices.outbox_cursor`. | `app/Services/SyncService.php:45`، migrations `2026_09_06_000001_create_sync_outbox_table`، `2026_09_06_000002_add_outbox_cursor_to_devices_table` |
| `docs/05-api-contract.md` | عقد Central ↔ POS للترخيص والـ heartbeat فقط، **مالوش علاقة** بمزامنة البيانات. | `docs/05-api-contract.md:1-12` |
| Cursor precedent | `StoreCatalogService::products` ترتيب `updated_at, uuid`، `PAGE_SIZE=100` + lookahead، cursor معتم base64url لـ `Y-m-d H:i:s|uuid`، tombstones `{uuid, deleted:true, deleted_at}`. نفس النمط هنستخدمه. | `app/Services/Store/StoreCatalogService.php:14,34-60,153-165,222-228` |
| Idempotency | مفيش `Idempotency-Key` في أي مكان. أوامر المتجر بتتفادى التكرار بـ `external_reference`. | `Services/Store/StoreOrderService.php:27,43,74` |
| تصنيع/BOM/إنتاج | مفيش كود. `StockMovement` أنواعه: purchase, sale, sale_return, purchase_return, transfer_*, adjustment_*, opening, internal_transfer, pharmacy_exchange_*, expired — **مفيش production_consume/receive**. | `app/Models/StockMovement.php:42-69` |
| نوع الصنف | `item_kind`/`is_sellable` مش موجودين؛ فيه `is_service` (bool) و`product_type` (نص حر) بس. | `app/Models/Product.php:75-83`، migration `2026_09_18_203743_add_is_service_to_products_table` |
| الأوركسترا الداخلية | `StockService::recordMovement` هو الكاتب الوحيد لحركات المخزون؛ الدفعات `ProductBatch` حالتها `active/depleted/expired/recalled`. | `app/Services/StockService.php:22`، migration `2026_03_07_000004_phase4_create_product_batches_table.php:28` |
| الصراعات واللوج | `SyncLog` (device_uuid, direction, table_name, records_count, status, error_message, started_at, finished_at)، `SyncConflict` (record_uuid, table_name, device_uuid, local/central payload + updated_at, direction, status, resolved_by) + `SyncConflictService` (resolveLocal/resolveCentral/dismiss). | `app/Models/SyncLog.php:9-18`، `app/Models/SyncConflict.php:9-21`، `app/Services/SyncConflictService.php:14-64` |

**الخلاصة:** مفيش HTTP API حالي يصلح لينا. لازم نبني "Formula Lab link" جديد فوق مفاهيم Sanad (outbox/cursor/SyncLog/SyncConflict) بدل ما نستخدم حاجة موجودة.

## خريطة الحقول (Sanad → Formula Lab)
كل الأرقام بتتنقل كـ decimal strings. "FL" = Formula Lab.

### المنتجات `Product` (`app/Models/Product.php:75-83`)
| حقل Sanad | استخدام FL |
| --- | --- |
| `uuid` | المفتاح الخارجي → `sanad_refs.sanad_uuid` (entity `product`) |
| `name`, `name_en` | اسم عرض؛ ما بيعدّلش على `substances` |
| `sku`, `barcode`, `alternate_barcodes` | ربط/مطابقة الصنف بـ `items`/`skus` |
| `item_kind` *(جديد، additive)*, `is_sellable` *(جديد)* | تصنيف: raw_material/packaging → `items`؛ finished_good → `skus`. لسه مش موجودين (PR) |
| `is_service`, `product_type` | تجاهل في FL (بنستخدم `item_kind`) |
| `cost_price` (decimal 2) | تكلفة Sanad الأخيرة؛ **مرجع مقارنة فقط**، تكلفة FL محسوبة من المشتريات |
| `sell_price`, `wholesale_price`, `guide_price` | قراءة للمقارنة؛ FL بيقترح بس (`PUT …/wholesale-prices`) |
| `tax_rate` | مدخل لمصفوفة الضرايب (يتراجع مع `tax_regimes`) |
| `unit`, `uom_id` + `ProductUnit.conversion_factor/is_purchase_default` + `Uom.ratio` | تحويل وحدة الشراء ⇄ وحدة المخزن (`items.purchase_uom/stock_uom/uom_factor`) |
| `stock`, `min_stock` | رصيد للقراءة (بيتجاب من `/stock`) |
| `status`, `deleted_at` | tombstone لو اتحذف |
| `updated_at` | cursor |

### الموردين `Supplier` (`app/Models/Supplier.php:27-45`)
| حقل Sanad | استخدام FL |
| --- | --- |
| `uuid` | `trade_materials.sanad_supplier_uuid`، `sanad_refs` |
| `name`, `name_en`, `code`, `tax_number`, `payment_terms` (int أيام) | عرض + شروط الدفع في تكلفة التمويل |
| `is_active` | فلتر |
| `current_balance`, `opening_balance`, phone/email/address | **مش بنقراها** (مالية = Sanad) |

### المشتريات `Purchase` (`app/Models/Purchase.php:18-44`)
| حقل Sanad | استخدام FL |
| --- | --- |
| `uuid`, `invoice_number`, `reference_number` | `landed_cost_charges.sanad_purchase_uuid`؛ مرجع الشحنة |
| `supplier_id` ← (مترجم لـ `supplier_uuid` في الـ payload) | ربط بالمورد |
| `purchase_date` | تاريخ سعر الشراء + FX المؤرخ |
| `status` (draft/confirmed/received/partial/cancelled) | بنحسب بس من `received`/`partial`؛ الباقي يتخطى |
| `subtotal`, `tax_amount`, `discount_amount`, `total` | تحقق مطابقة مع مجموع البنود؛ VAT مدخلات |
| `branch_id` | مخزن الإنتاج |
| `paid_amount`, `due_amount`, `payment_status` | **مش بنقراها** |

### بنود الشراء `PurchaseItem` (`app/Models/PurchaseItem.php:13-36`)
| حقل Sanad | استخدام FL |
| --- | --- |
| `uuid` | `sanad_purchase_lines.sanad_uuid` |
| `product_id` ← `product_uuid` | الصنف |
| `quantity` + `uom_id`/`product_unit_id` | كمية الشراء بوحدة الشراء |
| `base_quantity` | كمية بوحدة المخزن (أساس التوزيع والتكلفة) |
| `unit_cost`, `discount_*`, `tax_rate`, `tax_amount`, `subtotal` | سعر الشراء الفعلي قبل التكلفة الواصلة |
| `received_quantity` | الكمية اللي دخلت فعلًا (أساس توزيع landed cost) |
| `batch_number`, `expiry_date`, `production_date` | تتبع الدفعة + ربط COA |

### الدفعات `ProductBatch` (`app/Models/ProductBatch.php:36-55`)
`uuid`, `batch_number`, `product_id`, `purchase_id`, `supplier_id`, `cost_price`, `initial_quantity`, `remaining_quantity`, `base_quantity`, `remaining_base_quantity`, `expiry_date`, `production_date`, `status` (`active/depleted/expired/recalled`). استخدام FL: ربط COA بالدفعة (L1)، تتبع الدفعة المصنّعة بالتركيبة، ومقارنة الاستهلاك. FL **ما بيكتبش** دفعات خامات؛ دفعة المنتج التام بتتكوّن في Sanad عند `receive`.

### حركات المخزون `StockMovement` (`app/Models/StockMovement.php:14-32`)
`uuid`, `product_id`, `product_batch_id`, `branch_id`, `type`, `quantity`, `before_quantity`, `after_quantity`, `unit_cost`, `reference_type/id`, `created_at`. FL بيقرا بس (للتكلفة الفعلية في Q). الأنواع الجديدة `production_consume` و`production_receive` بتتضاف في PR Sanad (نفس أسلوب migration `2026_11_14_100200_widen_stock_movements_type_for_sale_edit_restore`، فرع sqlite + mysql/pgsql).

### أسعار الجملة
`WholesaleTier` (`name`, `discount_percent`, `sort_order`, `is_active`, `uuid`; `app/Models/WholesaleTier.php:17-24`) = **خصم نسبة عام**، مش سعر لكل منتج. أسعار الجملة لكل منتج في Sanad = `Product.wholesale_price` + `guide_price` فقط. عشان كده endpoint الأسعار بيكتب `wholesale_price`/`guide_price`/`sell_price` على المنتج (مش على الـ tiers)، والـ tier name اختياري في الـ payload كمعلومة.

### ⚠ دقة الأرقام (مفتوح)
أغلب الأموال في Sanad `decimal(12,2)` (مثلًا `stock_movements.unit_cost` — migration `2026_03_07_000005_phase4_create_stock_movements_table.php:24`؛ casts `decimal:2` على `PurchaseItem.unit_cost`/`Product.cost_price`) وكميات `quantity`/`base_quantity` على 2 خانة في بنود الشراء (الدفعات 8 خانات). خامة سعرها كسور قرش للجرام هتتقرّب في Sanad. **قاعدة FL:** نحسب سعر الوحدة من `subtotal ÷ base_quantity` (مش من `unit_cost`) ونخزن اللي استلمناه بالضبط، ولو الدقة مش كفاية نسجّلها `missing[]` ونكتب سطر في HUMAN-TODO — ما نخمّنش.

## تصميم النقل (Transport)
### 1) المسار الأساسي: Sanad بيبدأ الاتصال ("Formula Lab link")
Sanad Desktop على جهاز المصنع غالبًا ورا NAT، فالسيرفر ما يقدرش يوصله. فـ Sanad هو الـ client:

- **Push (Sanad → FL):** `POST /api/sanad/v1/changes` بيبعت batch تغييرات (products, suppliers, purchases + items, batches, stock movements) مرتبة بـ cursor `(updated_at, uuid)`، مع `Idempotency-Key` وbearer token. الرد فيه `accepted` و`cursor` الجديد و`conflicts[]`. Tombstones للمحذوف.
- **Pull (Sanad ← FL):** `GET /api/sanad/v1/outbox?cursor=` بيرجع أوامر الإنتاج المعتمدة، upserts المنتج التام، واقتراحات أسعار الجملة؛ بعد التنفيذ Sanad بيعمل `POST /api/sanad/v1/outbox/ack`.
- Sanad بيطبّق أوامر الإنتاج محليًا (`consume` ثم `receive` على `StockService`، FEFO، 422 لو الرصيد مش كفاية) وبيرد بالنتيجة في الـ ack.
- الـ cursor عند كل طرف: Sanad بيحتفظ بـ cursor الـ push وcursor الـ pull (جدول/إعداد خاص بالـ link، **مش** `devices.outbox_cursor` بتاع Central عشان ما نلخبطش مزامنته).
- Retries: exponential backoff في Sanad؛ التكرار آمن بسبب `Idempotency-Key`.
- Webhooks **ملغية** (الـ push بيغطيها).

### 2) بديل LAN مباشر (نسخة Electron أوفلاين)
لو FL على نفس الشبكة، FL يكلم Sanad المحلي مباشرة بنفس الـ payloads: `GET/PUT/POST /api/v1/manufacturing/*` ببيرر token بـ abilities (`manufacturing:read`/`manufacturing:write`). التفاصيل في `sanad-contract-v1.md`.

### 3) التعارضات واللوج
- أي تعارض (نفس الـ uuid اتعدّل في الطرفين، أو `updated_at` الوارد أقدم من المحلي) ⇒ **Sanad `SyncConflict`** (`record_uuid`, `table_name='formula_lab.<entity>'`, `local_payload`, `central_payload`, `direction`, `status=pending`) ويتحل بـ `SyncConflictService`. FL يرجّعه في `conflicts[]` بس ما يحلّهوش.
- كل دورة push/pull بتتسجل في **Sanad `SyncLog`** (`table_name='formula_lab.<entity>'`, `direction`, `records_count`, `status`, `error_message`, `started_at/finished_at`). FL له لوج خاص بيه لطلباته (`integration_logs`) لأغراض التتبع.
- قاعدة الملكية بتحسم: بيانات تجارية ⇒ Sanad يكسب؛ بيانات تركيبية/تكلفة ⇒ FL يكسب.

## الناقص في Sanad (G-03: PR على فرع `feat/manufacturing-api`، من غير merge)
1. Migration additive على `products`: `item_kind` (default `merchandise`: `merchandise|raw_material|packaging|finished_good`) + `is_sellable` (default `true`)، محروسة بـ `Schema::hasColumn`. enforcement في `app/Support/ProductSearch.php`، store catalog، mobile products، checkout، `StoreOrderService`، وفلاتر التقارير.
2. Idempotency middleware + جدول `api_idempotency_keys`.
3. Token بـ abilities `manufacturing:read|write`.
4. Endpoints `v1/manufacturing/*` (LAN) + أنواع حركة `production_consume`/`production_receive`.
5. Client الـ Formula Lab link (push/pull/ack/cursors/SyncLog/SyncConflict).
6. الاختبارات: **PHPUnit 11 فقط** (مش Pest — ده أسلوب Sanad، تصحيح لتاسك G-03 اللي كان بيقول Pest)، كل اختبارات Sanad الحالية خضرا من غير تعديل، + اختبارات: صنف `is_sellable=false` ما يتباعش ولا يظهر في `/api/v1/store/products`، بيتشري ويتجرد عادي.
- قبل أي تعديل في sanad: اتبع `AGENTS.md` (Route → FormRequest → Controller → Service → Action → Model، JS فقط، Tailwind 3، PHPDoc array shapes، `vendor/bin/pint --dirty`).

## قرارات صاحب المشروع (2026-11-21) — سارية
1. بنكلم Sanad POS اللي على جهاز المصنع (outbound من Sanad + LAN بديل) — متفصّل فوق.
2. `item_kind`/`is_sellable` additive؛ الخامات والفوارغ غير قابلة للبيع؛ المنتج التام `finished_good` قابل للبيع؛ سلوك Sanad الحالي ما يتغيرش.

## نقاط مفتوحة
- دقة الأرقام في Sanad (فوق) — محتاجة قرار: نطلب من Sanad يبعت أعمدة بدقة أعلى ولا نكتفي بـ `subtotal ÷ base_quantity`.
- شكل token الـ Formula Lab link: Sanctum ولا setting-based (قرار في PR Sanad).
- هل `Product.uuid`/`Purchase.uuid` مضمون وجودهم لكل الصفوف القديمة؟ (trait `GeneratesSyncUuid` بيولّده عند الإنشاء؛ لازم نتأكد من الصفوف القديمة قبل G-05).
