# مطلوب من صاحب المشروع
- [ ] شراء نصوص ES 1562، ES 4900، ES 6910، ES 2295 من EOS
- [ ] مراجعة مصفوفة الضرايب مع محاسب (VAT 14% للمنظفات من قوانين 2026؛ وضع العطور والتجميل) — A-13
- [ ] التعاقد مع كيميائي استشاري (golden tests + tier profiles)
- [x] Sanad: POS اللي على جهاز المصنع + نوع صنف additive (item_kind + is_sellable) — شوف plan/02-specs/sanad-integration.md
- [ ] جمع 30 تركيبة + 20 COA + أسعار 40 خامة + BOM تغليف 20 SKU + 5 شحنات استيراد (قوالب في plan/03-data-templates)
- [ ] قرار: أسعار وكميات Sanad بـ decimal(12,2) (خانتين). نكتفي بيها لتكلفة الخامات، ولا نوسّعها في PR بتاع Sanad؟ (G-01)
- [ ] F-07: وفّر/اعتمد مصدر خريطة خواص الصابون (soap property map: أي أحماض دهنية بتدخل في hardness/cleansing/conditioning/bubbly/creamy وiodine/INS — مثلاً اصطلاح soapcalc) مع sourceId ودرجة ثقة. المحرك مابيشحنش خريطة افتراضية.
- [ ] F-08: وفّر جدول rHLB للخامات (مع sourceId ودرجة ثقة؛ L7 مرفوض في الحساب النهائي إلا بـ override) وأرقام مجموعات Davies (Hi) لو عايز حاسبة Davies — المحرك بياخدهم كمدخلات بس.
