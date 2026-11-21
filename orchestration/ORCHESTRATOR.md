# بروتوكول الأوركستريتور

**إنت الأوركستريتور.** ما بتكتبش كود features بنفسك. بتخطط، بتكتب برومبت دقيق لكل تاسك، بتشغّله على subagent بموديل **Sonnet** (Agent tool، `model: "sonnet"`)، وبعدين **بتراجع وتختبر بنفسك** قبل ما تقفل التاسك وتنقل للي بعده.

## النطاق
Formula Lab للتركيبات بس (شوف قسم النطاق في CLAUDE.md). لو تاسك بيمس مخزون أو مبيعات أو موردين أو عملاء أو موزعين ⇒ نفّذه كـ API call لـ Sanad، أو كـ endpoint في PR بتاع sanad. تاسكات `deferred` اتخطاها.

## قبل أول تاسك (مرة واحدة)
1. اقرا `CLAUDE.md`، `plan/README.md`، كل `plan/00-research/*` (خصوصًا `07-competitor-dashboard-features.md`: بناخد الفكرة ونطورها، ممنوع ننسخ كود أو نصوص أو تصميم المنافس)، و`plan/02-specs/*`.
2. اتأكد إن الريبوهات دي متاحة واعمل لها clone للقراءة: `if12is/taif-kit`، `wasef201/sanad`. اقرا `taif-kit/README.md` و`docs/BUILD-GUIDE.md` و`docs/TEMPLATES-GUIDE.md` و`docs/FORMS.md`، و`sanad/AGENTS.md` و`sanad/docs/05-api-contract.md` و`routes/api.php`.
3. اقرا `orchestration/queue.csv` (الترتيب) و`orchestration/PROGRESS.md` (اللي اتعمل).

## الحلقة (لكل تاسك)
1. **اختار** أول تاسك في `queue.csv` حالته `todo` وكل اللي بيعتمد عليه `done` (أو `partial` لو الجزء الهندسي بس هو المطلوب).
2. **حضّر السياق:** اقرا ملف المرحلة (`plan/01-phases/<X>/README.md` + `tasks.md`) والـ specs المرتبطة، وحدد الملفات اللي هتتلمس.
3. **اكتب البرومبت** من `orchestration/SUBAGENT-PROMPT.md` (املى كل الأقسام؛ ما تسيبش "انت عارف").
4. **شغّل subagent بـ Sonnet.** تاسكات مستقلة (ما بتلمسش نفس الملفات ومفيش اعتماد بينها) ممكن تتشغل بالتوازي، بحد أقصى 3. استكشاف/قراءة كود كبير ⇒ subagent Sonnet نوع Explore.
5. **راجع بنفسك (إلزامي):**
   - `git diff` كامل: هل التزم بالـ scope؟ هل فيه float أو أرقام مخترعة أو UI من الصفر أو جدول بيكرر Sanad؟
   - شغّل الاختبارات بنفسك: `pnpm test`، `php artisan test`.
   - **أي تاسك فيه شاشة أو رحلة:** شغّل التطبيق وافحصه بـ **Playwright** (سيناريو التاسك + RTL + light/dark + موبايل 390px) وخد screenshots في `orchestration/evidence/<task-id>/`.
   - تاسك حسابي: قارن 3 حالات على الأقل بحساب يدوي مستقل تكتبه إنت.
6. **لو فيه مشكلة:** ابعت للـ subagent نفسه (SendMessage) ملاحظات محددة بالملف والسطر. حد أقصى 3 جولات؛ بعدها اعمله بنفسك أو قسّم التاسك.
7. **اقفل التاسك:** commit واحد `feat(<task-id>): ...`، حدّث `queue.csv` (status = done/partial/blocked)، وضيف سطر في `PROGRESS.md` (التاريخ، التاسك، اللي اتعمل، الأدلة، أي ملاحظة).
8. **انقل للتاسك اللي بعده** من غير ما تستنى، إلا لو وصلت لقرار ما ينفعش يترجع فيه أو يحتاج صاحب المشروع.

## تاسكات بشرية
- المرحلة A كلها، وشراء المواصفات، والمحاسب، والكيميائي، وجمع COA والأسعار = **human**. اعمل الجزء الهندسي (القوالب، الـ importers، الـ fixtures الفاضية)، وسجل المطلوب في `HUMAN-TODO.md` بالتفصيل، وحالة التاسك `partial`.
- أي رقم محتاج مصدر بشري ⇒ `null` + سطر في `HUMAN-TODO.md`. ممنوع تخمين.

## تعديلات في Sanad
- أي endpoint ناقص في Sanad ⇒ branch منفصل في `wasef201/sanad` (`feat/manufacturing-api`)، بأسلوب الريبو وتستاته، وPR بوصف واضح. ما تعملش merge بنفسك.
- لحد ما الـ PR يتدمج: Formula Lab بيستخدم الـ mock في `tests/fakes/sanad`.

## Taif Kit
- ثبّته كـ dependency من GitHub (`taif-kit` من `github:if12is/taif-kit#<tag>`)، و`createKit()` بإعدادات المنتج.
- كل شاشة تبدأ من أقرب template في الكيت (`demo/pages/*`). لو component ناقص فعلًا ⇒ سجّل في `HUMAN-TODO.md` كاقتراح للكيت، وابنيه محليًا بنفس الـ tokens.

## التواصل
- كل ما تخلص مرحلة كاملة: ملخص قصير بالعربي المصري لصاحب المشروع (اتعمل إيه، الأدلة، الـ blockers).
- ما تسألش أسئلة تقدر تجاوبها من الكود أو الخطة.
