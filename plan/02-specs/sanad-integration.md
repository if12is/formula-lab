# الربط مع Sanad POS

> أول نسخة من قراءة ريبو `wasef201/sanad` (commit ff39eb2، 2026-11-19). تاسك G-01 بيراجعها ويكمّلها.

## المبدأ
Sanad = نظام التجارة (POS، مشتريات، مخزون، مبيعات، عملاء، موزعين). Formula Lab = نظام التصنيع والتركيبات والتكلفة.
ما فيش جدول في Formula Lab بيكرر جدول تجاري في Sanad. الربط بـ `external_id` (uuid بتاع Sanad).

## ملكية البيانات (Source of truth)
| الكيان | المالك | Formula Lab بيعمل إيه |
| --- | --- | --- |
| الموردين (Supplier, SupplierLedgerEntry) | Sanad | قراءة + ربط COA بالمورد |
| المشتريات (Purchase, PurchaseItem, PurchasePayment) | Sanad | قراءة؛ إضافة landed cost overlay |
| الأصناف: خامات/فوارغ/كراتين (Product, ProductUnit, Uom, ProductBatch) | Sanad | ربط بـ trade_materials/items |
| المخزون (StockMovement, InventoryAdjustment, StockTransfer) | Sanad | كتابة حركات إنتاج عن طريق API |
| المنتج التام (Product) | Sanad | نشر SKU + تكلفة + سعر |
| أسعار الجملة/الموزعين (WholesaleTier) | Sanad | نشر الأسعار المقترحة من سلّم السعر |
| العملاء والمبيعات والمرتجعات (Customer, Sale, SaleReturn) | Sanad | قراءة للتقارير |
| الفروع (Branch) | Sanad | اختيار مخزن الإنتاج |
| التركيبات، COA، landed cost، التكلفة، الفئات، المطابقة، المعمل، أوامر الإنتاج | Formula Lab | — |

## اللي موجود في Sanad API النهارده
| Endpoint | Auth | يفيدنا في |
| --- | --- | --- |
| `GET /api/v1/store/products` | store.tenant + store.token | قراءة الأصناف |
| `GET /api/v1/store/stock`، `POST /api/v1/store/stock/check` | نفسه | أرصدة الخامات |
| `POST /api/v1/store/orders` | نفسه | (مش لينا) |
| `GET /api/mobile/suppliers`، `/purchases`، `/products`، `/sales` | Sanctum + mobile.device | قراءة موردين ومشتريات ومبيعات |
| `/api/v1/sync/*` | VerifyApiToken | حالة المزامنة |
| Central: `/api/v1/license/*` | — | (مش لينا) |

## الناقص (يتعمل PR في sanad — تاسك G-03)
| Endpoint مقترح | الغرض |
| --- | --- |
| `GET /api/v1/manufacturing/purchases?since=` | المشتريات بتفاصيل البنود والوحدات والدفعات |
| `PUT /api/v1/manufacturing/products/{external_id}` | upsert منتج تام أو خامة (idempotent) |
| `POST /api/v1/manufacturing/production-orders/{id}/consume` | صرف خامات وتغليف (StockMovement نوع production_consume) |
| `POST /api/v1/manufacturing/production-orders/{id}/receive` | إضافة منتج تام بدفعة (ProductBatch + StockMovement production_receive) |
| `PUT /api/v1/manufacturing/products/{id}/wholesale-tiers` | أسعار الموزعين |
| Webhook `purchase.created` / `purchase.updated` | تحديث التكلفة لحظيًا |
- كل write بـ `Idempotency-Key` header، وtoken بصلاحيات (abilities) خاصة بالتصنيع.
- **قبل أي تعديل في sanad:** اقرا `AGENTS.md` بتاعه والـ docs، واتبع أسلوبه وتستاته.

## قرارات صاحب المشروع (2026-11-21)

### 1) بنكلم Sanad POS اللي على جهاز المصنع (مش Central)
- Sanad POS تطبيق local-first (Electron + SQLite) على جهاز المصنع، وغالبًا ورا NAT، فالسيرفر ما يقدرش يوصله مباشرة.
- **التصميم:** Sanad POS هو اللي بيبدأ الاتصال (outbound)، بنفس نمط مزامنته الحالية مع Central:
  - Sanad يبعت (push) المشتريات والأصناف والأرصدة لـ Formula Lab عن طريق outbox + cursor.
  - Sanad يسحب (pull) من Formula Lab: أوامر الإنتاج المعتمدة (صرف خامات + استلام منتج تام)، والمنتجات التامة بتكلفتها وأسعارها، وأسعار الموزعين.
  - كل رسالة ليها `Idempotency-Key`، وأي تعارض يتسجل ويتحل بنفس آلية SyncConflict الموجودة في Sanad.
- لو Formula Lab شغال على نفس الشبكة المحلية (نسخة Electron)، ينفع اتصال مباشر LAN كبديل، بنفس العقد.
- G-01 لازم يقرا كود المزامنة الحالي في Sanad (`docs/sync/`، SyncApiController، SyncLog، SyncConflict) ويبني عليه، مش جنبه.

### 2) الخامات والفوارغ: نوع صنف متوافق مع اللي موجود (additive)
**الشرط:** ما يحصلش أي لخبطة في Sanad الحالي، ويدعم الجديد.
- Migration إضافية بس في `products` (مفيش تعديل أو حذف لأعمدة موجودة):
  - `item_kind` (string، default `merchandise`): `merchandise` | `raw_material` | `packaging` | `finished_good`
  - `is_sellable` (boolean، default `true`)
- كل المنتجات الحالية بتاخد `merchandise` + `is_sellable = true` ⇒ سلوك Sanad الحالي ما يتغيرش خالص.
- الخامات والفوارغ (`raw_material` و`packaging`) بتبقى `is_sellable = false`: ما تظهرش في شاشة البيع ولا الـ store API، بس بتظهر في المشتريات والمخزون والجرد عادي.
- المنتج التام اللي جاي من Formula Lab بيبقى `finished_good` وقابل للبيع.
- التقارير الحالية: فلتر افتراضي يحافظ على نفس الأرقام القديمة، وفلتر جديد حسب النوع.
- **اختبارات إلزامية في PR الخاص بـ sanad:** كل اختبارات Sanad الحالية خضرا من غير تعديل + اختبارات جديدة: صنف بـ `is_sellable = false` ما يتباعش، وما يظهرش في `/api/v1/store/products`، وبيتشري ويتجرد عادي.

## أسئلة مفتوحة
- (اتحلت — شوف القرارات فوق)
