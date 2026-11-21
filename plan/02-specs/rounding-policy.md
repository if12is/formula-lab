# سياسة التقريب
| الكمية | داخليًا | العرض |
| --- | --- | --- |
| الوزن | Decimal كامل | 0.1 جم (ورقة الوزن: حسب دقة الميزان المسجّل) |
| الحجم | Decimal كامل | 1 مل |
| النسبة % | Decimal كامل | 0.001% |
| الفلوس | Decimal كامل | 0.01 جنيه |
| pH / HLB | Decimal كامل | 0.1 |

- التقريب ROUND_HALF_EVEN، ومرة واحدة عند العرض/الطباعة فقط.
- المجاميع بتتحسب من القيم غير المقربة، والفرق الناتج عن التقريب يتعرض لو > 0.
- التنفيذ: [`packages/engine/src/core/round.ts`](../../packages/engine/src/core/round.ts) (`roundMass`, `roundVolume`, `roundPercent`, `roundMoney`, `roundPhHlb`, `roundingResidual`). الوزن بيتعرض بالجرام، والحجم بالمل، والفلوس بعملتها. `roundingResidual` بيرجّع الفرق (إجمالي مقرّب − مجموع الأجزاء المقرّبة) من غير ما يوزّعه.
