# تاسكات المرحلة D: نموذج الداتا والمصادر

## [ ] D-01 — تصميم الـ schema (substances, trade_materials, suppliers, coa, coa_values, specs, prices, sources, dataset_versions, formulas, formula_versions, batches, lab_results, tiers, rules)
- **المخرج:** 02-specs/data-model.md
- **شرط القبول:** مراجَع
- **يعتمد على:** —

## [ ] D-02 — فصل substance (مادة كيميائية: CAS, MW) عن trade_material (درجة تجارية: SLES 70% من مورد X)
- **المخرج:** migrations
- **شرط القبول:** مادة واحدة ليها درجات متعددة
- **يعتمد على:** D-01

## [ ] D-03 — جدول property_values عام: (entity, property, value, unit, min, max, source_id, confidence L1–L7, valid_from)
- **المخرج:** migration
- **شرط القبول:** أي خاصية بمدى أو قيمة
- **يعتمد على:** D-01

## [ ] D-04 — قاعدة اختيار القيمة: أعلى ثقة متاحة ثم الأحدث، مع إمكانية override يدوي مسجّل
- **المخرج:** engine/data/resolve.ts
- **شرط القبول:** مختبرة بحالات تعارض
- **يعتمد على:** D-03

## [ ] D-05 — dataset_versions: snapshot hash لكل نسخة، والحساب يسجل النسخة المستخدمة
- **المخرج:** migration + service
- **شرط القبول:** نفس الحساب يتعاد بعد سنة بنفس الناتج
- **يعتمد على:** D-03

## [ ] D-06 — formula_versions: كل تعديل نسخة جديدة + hash + سبب التعديل + حالة (draft/lab/approved)
- **المخرج:** migration
- **شرط القبول:** مفيش تعديل يمسح نسخة قديمة
- **يعتمد على:** D-01

## [ ] D-07 — Audit log لكل تعديل في الداتا المرجعية
- **المخرج:** audit table
- **شرط القبول:** مين/إمتى/قبل/بعد
- **يعتمد على:** D-01

## [ ] D-08 — مرآة SQLite للأوفلاين بنفس الـ schema (subset)
- **المخرج:** apps/desktop/db
- **شرط القبول:** مزامنة جدول واحد تشتغل
- **يعتمد على:** D-01

## [ ] D-09 — مادة جديدة من المحرر تتسجل draft بثقة L7 وسعر غير مؤرخ ⇒ التكلفة تتعلّم غير موثوقة
- **المخرج:** integration
- **شرط القبول:** الحساب يرجع warning واضح
- **يعتمد على:** D-04

## [ ] D-10 — النسخة المعتمدة immutable + diff بين أي نسختين (سطور، نسب، خطوات، مصادر)
- **المخرج:** formula_versions + diff
- **شرط القبول:** مفيش update/delete على approved
- **يعتمد على:** D-06

