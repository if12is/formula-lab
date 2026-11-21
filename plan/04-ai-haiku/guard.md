# Number Guard (الحارس)

هدفه: ضمان إن ولا رقم في رد Haiku اتخلق.

## الخوارزمية
1. اجمع كل الأرقام من tool results في الجلسة (بعد تطبيعها: أرقام عربية ⇒ لاتينية، فواصل، تقريب العرض).
2. استخرج كل الأرقام من رد Haiku (regex للأرقام العربية واللاتينية والكسور والنسب).
3. استثني: أرقام موجودة في رسالة المستخدم نفسه، أرقام المواصفات (ES 1562)، التواريخ، أرقام الخطوات (1. 2. 3.).
4. أي رقم باقي مش مطابق ⇒ الرد يترفض، ويتبعت لـ Haiku: "الرقم X مش من أي tool. استدعي الـ tool المناسب أو احذفه."
5. محاولتين بحد أقصى، وبعدها يتعرض رد آمن بدون أرقام + زرار "احسب".

```ts
export function guard(reply: string, toolNumbers: Set<string>, userNumbers: Set<string>): string[] {
  const found = extractNumbers(normalizeDigits(reply));
  return found.filter(n => !toolNumbers.has(n) && !userNumbers.has(n) && !isAllowedToken(n, reply));
}
```

## اختبارات لازمة
- رد فيه "حوالي 12%" مش من tool ⇒ مرفوض
- رد بيعيد رقم المستخدم ⇒ مقبول
- رد بأرقام عربية "١٢٫٥" مطابقة لـ 12.5 من tool ⇒ مقبول
