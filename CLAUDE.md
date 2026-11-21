# Formula Lab — منصة التركيبات الحتمية

اقرا الأول: `plan/README.md` ثم `orchestration/ORCHESTRATOR.md`.

## إيه اللي بنبنيه
منصة لمصانع المنظفات ومستحضرات التجميل والعطور والمعطرات والمرطبات في مصر: تركيبات، تكلفة كاملة (خامات واصلة + تغليف + تحويل + ضرايب + سلّم سعر)، فئات (اقتصادي/متوسط/فاخر) بمُحسِّن LP، مطابقة (ES/CosIng/IFRA)، معمل، إنتاج، وClaude Haiku كمساعد بيستدعي المحرك بس.

## قواعد لا تُكسر
1. **Deterministic:** كل رقم من `packages/engine` (TypeScript pure + decimal.js). مفيش float، مفيش Date.now/random في المحرك. الـ AI عمره ما يحسب.
2. **كل قيمة ليها مصدر ودرجة ثقة (L1–L7).** قيمة ناقصة ⇒ الحساب يقف ويرجع `missing[]`، مش صفر.
3. **ما نعيدش اختراع العجلة:**
   - **POS والتجارة = Sanad** (`wasef201/sanad`): موردين، مشتريات، مخزون، مبيعات، عملاء، موزعين، أسعار جملة، فواتير. بنكلمه بالـ API (`plan/02-specs/sanad-integration.md`). ممنوع جداول موازية.
   - **UI = Taif Kit** (`if12is/taif-kit`): كل الشاشات من components والـ templates بتاعته. ممنوع components من الصفر لو الكيت فيه بديل.
4. **ما فيش حاجة "شغالة" من غير اختبار:** Vitest للمحرك، Pest لـ Laravel، **Playwright** لأي شاشة أو رحلة مستخدم.
5. **ممنوع اختراع أرقام كيميائية أو تنظيمية** (حدود مواصفات، نسب فئات). الحقل يفضل `null` + سطر في `orchestration/HUMAN-TODO.md`.
6. اللغة: الواجهة عربي RTL افتراضي. التعليقات والـ commits إنجليزي. الكلام مع صاحب المشروع بالعربي المصري.

## الستاك
Laravel 12 + PostgreSQL + Inertia + Vue 3 + Taif Kit + Tailwind 4 · `packages/engine` و`packages/optimizer` (HiGHS WASM) · Electron (أوفلاين) · Claude Haiku عبر API من السيرفر.

## أوامر
(تتحدث بعد B-01) `pnpm test` · `php artisan test` · `pnpm playwright test`
