# Tier Profiles (اقتصادي / متوسط / فاخر)

الفئة = مجموعة قيود ثابتة. المُحسِّن بيطلع أرخص تركيبة تحققها. نفس القيود + نفس الأسعار = نفس التركيبة.

## Schema
```json
{
  "productType": "dishwashing_liquid",
  "tier": "economy|mid|premium",
  "targetRetailPriceEGP": {"per": "L", "value": null},
  "constraints": {
    "totalActiveMinPct": "FROM_ES_1562",         
    "totalActiveMaxPct": null,
    "functionBounds": [ {"function": "anionic_surfactant", "min": null, "max": null},
                        {"function": "amphoteric_surfactant", "min": null, "max": null},
                        {"function": "fragrance", "min": null, "max": null} ],
    "requiredMaterials": [], "forbiddenMaterials": [],
    "maxCostEGPperKg": null,
    "measuredRequirements": ["viscosity_mPas", "pH", "stability_4w"]
  },
  "sources": { "totalActiveMinPct": "ES 1562 clause ?", "functionBounds": "CPDat/HERA ranges + chemist decision" }
}
```

## إزاي الفئات بتختلف (منطق مش أرقام)
| | اقتصادي | متوسط | فاخر |
| --- | --- | --- | --- |
| المادة الفعالة | الحد الأدنى للمواصفة + هامش أمان | وسط نطاق CPDat للفئة | أعلى + سطحي ثانوي لطيف (CAPB / amine oxide) |
| السطحيات | LABSA أساسًا | LABSA + SLES | SLES/CAPB/APG، أقل تهييج |
| العطر | أقل حد مقبول | متوسط | أعلى (داخل حد IFRA) |
| إضافات | لا | ملح للزوجة | مرطب، لون ثابت، ادعاءات بيئية (DID) |

مرجع مثال (مش قيد): بحث مصري اقترح سائل أطباق 10% LAS + 2% SLES + 2% DEA أو amine oxide (Egypt. J. Chem. 2018).

**ممنوع ملء أي رقم من غير مصدر.** الحقول `null` تتملى في J-02 من المواصفة + CPDat/HERA + قرار الكيميائي.
