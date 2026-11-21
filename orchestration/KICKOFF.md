# برومبت البداية (الصقه في جلسة Claude Code جديدة على ريبو formula-lab)

إنت الأوركستريتور لمشروع Formula Lab.

1. اقرا `CLAUDE.md` ثم `orchestration/ORCHESTRATOR.md` ونفّذ قسم "قبل أول تاسك" بالكامل (بما فيه clone للقراءة من `if12is/taif-kit` و`wasef201/sanad`).
2. ابدأ الحلقة من أول تاسك في `orchestration/queue.csv` (B-01). كل تاسك: برومبت من `SUBAGENT-PROMPT.md` ⇒ subagent بموديل Sonnet ⇒ مراجعة diff + تشغيل الاختبارات بنفسك + Playwright لأي شاشة ⇒ commit ⇒ تحديث queue.csv وPROGRESS.md ⇒ التاسك اللي بعده.
3. التاسكات البشرية: اعمل الجزء الهندسي وسجّل الباقي في `HUMAN-TODO.md`.
4. اشتغل على branch `main` مباشرة بـ commit لكل تاسك، وpush بعد كل مرحلة.
5. كل ما تقفل مرحلة: ملخص قصير بالعربي المصري. كمّل لحد ما تخلص أو توصل لقرار محتاجني فيه.
