# تاسكات المرحلة P: طبقة Claude Haiku

## [ ] P-01 — AI proxy في Laravel (المفتاح على السيرفر، rate limit، logging، cost tracking)
- **المخرج:** apps/api/ai
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-02 — تعريف tools: get_material, calculate_formula, optimize_tier, check_compliance, convert_units, explain_result, extract_coa
- **المخرج:** 04-ai-haiku/tools.json
- **شرط القبول:** schemas strict
- **يعتمد على:** —

## [ ] P-03 — System prompt بالعربي المصري: ممنوع حساب أو تقدير رقم، كل رقم من tool
- **المخرج:** 04-ai-haiku/system-prompt.md
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-04 — Number guard: كل رقم في رد Haiku لازم يطابق رقم في tool results وإلا الرد يترفض ويتعاد
- **المخرج:** ai/guard.ts
- **شرط القبول:** مختبر على ردود فيها أرقام مختلقة
- **يعتمد على:** P-02

## [ ] P-05 — COA extraction: Haiku يقرا PDF/صورة ⇒ JSON بمستوى ثقة ⇒ إنسان يأكد قبل الحفظ (L1 بعد التأكيد بس)
- **المخرج:** ai/coa
- **شرط القبول:** مفيش حفظ تلقائي
- **يعتمد على:** —

## [ ] P-06 — Data cleaning assistant: يكتشف تعارضات (SAP برة نطاق Codex، active غير منطقي) ويقترح، والقرار للإنسان
- **المخرج:** ai/data-qa
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-07 — Formulation assistant: يحول طلب المستخدم ('عايز شامبو اقتصادي بـ 35 جنيه') لـ tier profile + optimize_tier
- **المخرج:** ai/assistant
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-08 — temperature 0 + structured outputs + prompt caching للـ system prompt والـ tools
- **المخرج:** config
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-09 — Eval set: 150 سؤال (حساب، مطابقة، فخاخ لاختلاق أرقام) وتشغيله في CI أسبوعيًا
- **المخرج:** 04-ai-haiku/evals.md
- **شرط القبول:** ≥ 98%
- **يعتمد على:** —

## [ ] P-10 — سياسة خصوصية: التركيبات ما تتبعتش للـ AI إلا بموافقة المستخدم لكل جلسة
- **المخرج:** policy
- **شرط القبول:** مراجعة من صاحب المرحلة
- **يعتمد على:** —

## [ ] P-11 — Tools جديدة: landed_cost, packaging_bom, price_waterfall, fragrance_blend, emulsion_phases
- **المخرج:** 04-ai-haiku/tools.json
- **شرط القبول:** schemas strict
- **يعتمد على:** —

## [ ] P-12 — Haiku يقرا فواتير الموردين وبوالص الشحن ⇒ مسودة landed cost ⇒ تأكيد إنسان
- **المخرج:** ai/invoices
- **شرط القبول:** مفيش حفظ تلقائي
- **يعتمد على:** —

