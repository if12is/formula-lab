# طبقة Claude Haiku

الدور: **مساعد ومنظّم (orchestrator)** مش آلة حاسبة.
- بيفهم طلب المستخدم بالعامية ⇒ يستدعي tools المحرك ⇒ يشرح النتيجة.
- بيقرا COA/TDS ويطلع JSON ⇒ إنسان يأكد ⇒ يتحفظ.
- بيكتشف تعارضات في الداتا ويقترح، والقرار للإنسان.
- **عمره ما يطلع رقم من عنده.** الـ guard بيرفض أي رد فيه رقم مش جاي من tool.

الموديل: أحدث Claude Haiku عبر الـ API (مثلًا `claude-haiku-5-5`)، temperature 0، من السيرفر فقط.
التكلفة: prompt caching للـ system prompt وتعريفات الـ tools.

الملفات: `system-prompt.md`، `tools.json`، `guard.md`، `evals.md`.
