# تحليل المنافس: Formula Studio

المصدر: تحليل كود الويب آب المنشور علنًا (https://app.formulastudio.work) وصفحة الهبوط (https://landing.formulastudio.work). تاريخ التحليل: 2026-11-21.

## الستاك
- React + Vite، RTL عربي، Capacitor (أندرويد) + Electron (ويندوز/ماك).
- SQLite محلي (mobile_records, mobile_sync_state, mobile_sync_revisions, mobile_sync_outbox).
- مزامنة وAPIs على Cloudflare Pages/Workers: /api/library, /api/directory, /api/ads, /api/subscription/pricing, /api/offline-license, /api/version.
- ربط بنظام مبيعات/POS (Formula Sales) يبعت المنتج بتكلفة الوحدة وسعر البيع.

## المعادلات الفعلية في الكود
- تكلفة الكيلو = Σ (النسبة/100 × سعر الكيلو)
- الكمية الإجمالية = الدفعة / (1 − الفاقد/100)
- عدد العبوات = ceil(الدفعة بالكيلو / حجم العبوة بالكيلو)
- تكلفة الدفعة = تكلفة الكيلو × الكمية الإجمالية + العبوات × (عبوة + غطاء + ليبل + كرتونة)
- سعر البيع بالهامش = تكلفة الوحدة / (1 − الهامش/100)
- القلوي = Σ(وزن الزيت × SAP) × (1 − Superfat)  — بدون نقاوة
- الماء = القلوي × نسبة (افتراضي 2)
- Required HLB = متوسط مرجّح بالكتلة، ومزج مستحلبين خطي

## الداتا المدمجة
- 138 زيت (SAP NaOH/KOH + 7 خصائص) من soapformulator.com — رخصة ODbL.
- ~51 قيمة required HLB من مواقع DIY (diy-cosmetics.com, Magis Pharma) معلّمة "تقريبي مرجعي".
- قواعد داخل الداتا زي never_invent_missing_values_or_sources ⇒ غالبًا اتجمعت بـ AI.

## الثغرات = فرصتك
| ثغرة | ميزتك |
| --- | --- |
| مفيش كثافة، كله كيلو | لتر ↔ كيلو بكثافة مقاسة للمنتج النهائي |
| مفيش نقاوة قلوي | purity من COA (KOH غالبًا ~90%) |
| مفيش مادة فعالة للخامات | active matter لكل درجة تجارية + مجموع active للمنتج |
| مفيش معادلة سلفونيك | NaOH من acid value في COA |
| SAP قيمة واحدة | SAP الدفعة من COA أو نطاق Codex |
| JavaScript float | Decimal + سياسة تقريب |
| مفيش مطابقة مواصفات | فحص ES / CosIng / IFRA + توليد بطاقة |
| مفيش تحسين تكلفة | LP يطلع أرخص تركيبة تحقق مواصفات الفئة |
| مكتبة تركيبات غير موثقة | قوالب بدرجة ثقة + حلقة تجربة معملية |

## تنبيه قانوني
ما تنسخش كودهم أو نصوصهم أو مكتبة تركيباتهم. داتا soapformulator مسموحة بـ ODbL بشرط النسبة ومشاركة أي تعديل على الداتابيز نفسها.
