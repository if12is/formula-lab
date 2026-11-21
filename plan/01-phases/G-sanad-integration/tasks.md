# تاسكات المرحلة G: الربط مع Sanad POS + التكلفة الواصلة

## [ ] G-01 — قراءة كود Sanad (routes/api.php، docs/05-api-contract.md، Models: Product, ProductUnit, Uom, ProductBatch, Supplier, Purchase, PurchaseItem, StockMovement, WholesaleTier, Sale) وكتابة خريطة: إيه موجود وإيه ناقص
- **المخرج:** 02-specs/sanad-integration.md
- **شرط القبول:** مراجعة الأوركستريتور
- **يعتمد على:** —

## [ ] G-02 — تحديد مصدر الحقيقة لكل كيان: Sanad (موردين، مشتريات، مخزون، مبيعات، عملاء، موزعين، أسعار جملة) / Formula Lab (تركيبات، COA، landed cost، تكلفة، فئات، مطابقة، معمل)
- **المخرج:** sanad-integration.md §ownership
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] G-03 — تصميم الـ endpoints الناقصة في Sanad (مثلًا: upsert منتج، استلام إنتاج تام، صرف خامات لأمر إنتاج، قراءة مشتريات بتفاصيلها، webhook لتغيّر سعر شراء) — كـ PR منفصل في ريبو sanad
- **المخرج:** sanad PR + docs
- **شرط القبول:** Pest tests في sanad خضرا
- **يعتمد على:** G-01

## [ ] G-04 — SanadClient في Formula Lab (Laravel HTTP client، Bearer token، idempotency key، retries، logging)
- **المخرج:** app/Integrations/Sanad
- **شرط القبول:** Contract tests بـ Http::fake
- **يعتمد على:** G-03

## [ ] G-05 — مزامنة الأصناف: الخامات والفوارغ والكراتين متسجلة كمنتجات في Sanad بفئات؛ Formula Lab يربطها بـ trade_materials/items بـ external_id
- **المخرج:** sync job
- **شرط القبول:** مزامنة idempotent
- **يعتمد على:** G-04

## [ ] G-06 — مزامنة الموردين والمشتريات (قراءة فقط) لحساب متوسط سعر الشراء الفعلي
- **المخرج:** sync job
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** G-04

## [ ] G-07 — Landed cost overlay: بنود الشحن والجمارك والتخليص والتمويل تتسجل في Formula Lab مربوطة بـ purchase_id من Sanad وتتوزع على أصنافه
- **المخرج:** engine/landed-cost.ts + tables
- **شرط القبول:** مطابق لـ 5 شحنات حقيقية
- **يعتمد على:** G-06

## [ ] G-08 — FX مؤرخ + VAT مدخلات حسب المصفوفة الضريبية
- **المخرج:** engine/tax.ts
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** A-13

## [ ] G-09 — نشر المنتج التام لـ Sanad (SKU، باركود، وحدة، تكلفة الوحدة، سعر بيع، أسعار جملة للموزعين) بعد اعتماد التكلفة
- **المخرج:** push job
- **شرط القبول:** يظهر في Sanad POS
- **يعتمد على:** I-01

## [ ] G-10 — Mock server لـ Sanad API للتطوير والـ e2e (نفس العقد)
- **المخرج:** tests/fakes/sanad
- **شرط القبول:** Playwright بيشتغل من غير Sanad حقيقي
- **يعتمد على:** G-04

