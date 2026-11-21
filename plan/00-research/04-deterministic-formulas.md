# المعادلات الحتمية (Deterministic) — المرجع الحسابي

كل المعادلات دي بتتنفذ بـ Decimal، وكل مدخل ليه وحدة ومصدر.

## الكتلة والتكلفة
- Σ pᵢ = 100.000 (تسامح 0.001) وإلا الحساب يقف
- mᵢ = M × pᵢ / 100
- M_gross = M / (1 − w/100)            (w = نسبة الفاقد)
- C_kg = Σ (pᵢ/100 × Pᵢ)               (Pᵢ سعر الكيلو بتاريخ)
- C_batch = C_kg × M_gross + N × C_pack
- N = floor(V_batch / V_bottle) ، والباقي يتعرض كمية منفصلة
- Price = C_unit / (1 − margin/100)

## الحجم والكثافة
- m = V × ρ_product   (ρ مقاسة للمنتج النهائي، مش محسوبة من المكونات)
- لو ρ مش مقاسة: الحساب يطلع بالكيلو بس ويطلب قياس

## المادة الفعالة والتخفيف
- A_total = Σ (mᵢ × aᵢ) / Σ mᵢ        (aᵢ = active matter للدرجة التجارية)
- m_raw = M × A_target / a_raw

## المعادلة (Neutralization) — LABSA
- m_NaOH = m_LABSA × AV / 1000 × 39.997 / 56.106 / purity_NaOH
- AV = acid value من COA (mg KOH/g) وبيشمل الحمض الحر
- ملحوظة: التعديل النهائي للـ pH بيتقاس، والمعادلة بتدي نقطة البداية الحتمية

## التصبين
- Lye = (Σ mᵢ × SAPᵢ) × (1 − SF/100) / purity
- SAP_NaOH = SAP_KOH × 39.997 / 56.106 (لو SAP بالـ mg KOH/g: ÷1000)
- الماء: 3 طرق (نسبة للقلوي، تركيز المحلول %، نسبة من الزيوت) بتحويل حتمي بينهم
- خواص الصابون من fatty acid profile (lauric+myristic = cleansing… إلخ) وتتعلّم "مؤشر"

## HLB
- Required HLB = Σ (mᵢ × rHLBᵢ) / Σ mᵢ  (لطور الزيت بس)
- مزج مستحلبين: f_high = (HLB_t − HLB_low) / (HLB_high − HLB_low)، لازم 0 ≤ f ≤ 1
- Griffin: HLB = 20 × Mh / M ؛ Davies: HLB = 7 + Σ Hᵢ − 0.475 n

## اللزوجة (من قياساتك مش تنبؤ)
- منحنى الملح (salt curve): نقاط مقاسة (NaCl%, mPa·s) لكل تركيبة أساس
- القيمة بين نقطتين = interpolation خطي، وبرة المدى = "خارج المقاس" (ممنوع extrapolation)

## المطابقة
- لكل قيد: value ≤ limit أو ≥ min ⇒ PASS / FAIL / UNKNOWN (لو داتا ناقصة)
- مجموع النسب للمواد اللي ليها حد مشترك: Σ (cᵢ / limitᵢ) ≤ 1 (قاعدة CosIng Annex III)

## التحسين (LP) للفئات
- minimize Σ xᵢ × Pᵢ
- s.t. Σ xᵢ = 1 ، lᵢ ≤ xᵢ ≤ uᵢ ، Σ xᵢ aᵢ ≥ A_min(tier) ، قيود المطابقة ، قيود الفئة الوظيفية (frame)
- HiGHS بيدي نفس الحل لنفس المدخلات؛ لو مفيش حل ⇒ يرجع القيود المتعارضة (IIS)
