# Progress Log
| التاريخ | التاسك | الحالة | اللي اتعمل | الأدلة |
| --- | --- | --- | --- | --- |
| 2026-11-21 | B-01 | done | ADR-000: الستاك + الـ monorepo (UI جوه apps/api بسبب Inertia، مفيش apps/web) + ربط Sanad | docs/adr/000-stack.md |
| 2026-11-21 | B-03 | done | ADR-001: محرك TS واحد + decimal.js + Node sidecar للسيرفر | docs/adr/001-shared-ts-engine-decimal.md |
| 2026-11-21 | B-04 | done | ADR-002: الـ AI ما يحسبش + number guard | docs/adr/002-ai-never-calculates.md |
| 2026-11-21 | B-05 | done | ADR-003: داتا مرجعية immutable بـ hash + audit append-only | docs/adr/003-immutable-versioned-reference-data.md |
| 2026-11-21 | G-01 | done | مراجعة كود Sanad وتصحيح spec الربط (store API للبورتال بس، المزامنة DB-level) + عقد HTTP v1 | plan/02-specs/sanad-integration.md, orchestration/context/sanad-contract-v1.md |
| 2026-11-21 | G-02 | done | مصدر الحقيقة لكل كيان + شيل جداول الموردين/المشتريات من الـ schema واستبدالها بـ sanad_refs وcache | plan/02-specs/data-model.sql |
| 2026-11-21 | B-02 | done | monorepo pnpm: packages/engine + optimizer، apps/api (Laravel 12 + Inertia 2 + Vue 3 + Tailwind 4 + Pest + pgsql)، ESLint بيمنع Date.now/Math.random/Number في المحرك | pnpm test/typecheck/lint ✓، php artisan test 2 ✓، e2e 1 ✓؛ evidence/B-02 |
| 2026-11-21 | B-06 | partial-verified | CI: js + php (postgres) + e2e | .github/workflows/ci.yml (YAML صالح؛ هيتأكد أول run على GitHub) |
| 2026-11-21 | B-07 | done | docker compose للتطوير (postgres, redis, app, vite) | docker compose config ✓ (الـ containers ما اتشغلتش في البيئة دي) |
| 2026-11-21 | B-08 | done | عقد المحرك كـ types + canonical JSON + SHA-256 (noble) + قسم versioning | hash.test.ts |
| 2026-11-21 | C-01 | done | core/decimal.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-07 | done | core/result.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-02 | done | core/quantity.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-03 | done | core/convert.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-04 | done | core/round.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-05 | done | core/fx.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-09 | done | core/count-units.ts | vitest ✓ + coverage 100% للملف + فحص يدوي مستقل (evidence/C) |
| 2026-11-21 | C-06 | done | property tests fast-check 5×10,000 (seed 42) | tests/core.prop.test.ts |
| 2026-11-21 | C-08 | done | جداول OIML R22 عند 20°C من الصيغة الرسمية (A1..A12) + interpolation بدون extrapolation | 50 نقطة من الجدول المطبوع (Table IIIa/IIIb) ✓، coverage 100% |
| 2026-11-21 | F-08 | done | HLB: required (oil phase) + blend + Griffin + Davies (group numbers مدخلات بمصدر) | فحص يدوي: rHLB=8، f_high=0.62617، Griffin=14 ✓ |
| 2026-11-21 | F-09 | done | منحنى الملح: interpolation على القياسات بس، بره المدى = missing | فحص يدوي: 1.5% ⇒ 1250 mPa·s، 2.5% ⇒ out_of_measured_range ✓ |
| 2026-11-21 | F-07 | partial | خواص الصابون كمؤشر من profile الأحماض بخريطة خصائص بمصدر (مفيش خريطة افتراضية) | الخريطة نفسها في HUMAN-TODO |
| 2026-11-21 | D-04 | done | اختيار القيمة: override ⇒ أعلى ثقة ⇒ الأحدث ⇒ sourceId، وتعارضات ظاهرة | resolve.test.ts |
| 2026-11-21 | F-10 | done | trace tree لكل رقم ناتج بمصادر ودرجة ثقة الأوراق + بوابة الثقة (L7 ⇒ missing إلا بـ override موقّع) | trace.test.ts، sourced.test.ts |
| 2026-11-21 | F-14 | done | مكوّن موازنة q.s. واحد معلن + balanceEvent للتاريخ | اختبار: باقي السطور ما بتتغيرش |
| 2026-11-21 | F-15 | done | نسبة المادة من وزنها وحجم التشغيلة بوحدات صريحة (حجم ⇒ كثافة بمصدر) | percent-from-weight.test.ts |
| 2026-11-21 | F-01 | done | Σ=100.000 صارم (±0.001)، سالب/صفر/مكرر ⇒ أخطاء بـ message keys، draftSummary للمسودة، scaleFormula | formula.test.ts |
| 2026-11-21 | F-02 | done | تكلفة الكيلو/اللتر/العبوة + فاقد + هامش (0≤m<100)، كل الخامات الناقصة السعر بتظهر مرة واحدة، تغليف لازم له مصدر | 20 حالة يدوية golden ✓ + فحص يدوي مستقل مني: 16.2 / 16.5306 / 20.3571 / 25.4464 |
| 2026-11-21 | F-03 | done | الدفعة: floor + باقي ظاهر، لتر↔كيلو بالكثافة المقاسة، اختبار يمنع ceil + calculate() موحد | فحص يدوي: 100 كجم ⇒ 95 عبوة لتر + 238.095 مل باقي ✓ |
| 2026-11-21 | D-01 | done | data-model.md: ER + ملكية كل جدول (Formula Lab vs Sanad cache) + tenancy | plan/02-specs/data-model.md |
| 2026-11-21 | D-02 | done | substances منفصلة عن trade_materials + tenants + DecimalString cast (مفيش float) | TradeMaterialTest: مادة واحدة بدرجات متعددة ✓ |
| 2026-11-21 | D-07 | done | audit_log append-only (trigger يمنع UPDATE/DELETE/TRUNCATE) + Auditable trait: مين/إمتى/قبل/بعد/ليه | AuditLogTest ✓ |
| 2026-11-21 | D-03 | done | sources + property_values (قيمة أو مدى، ثقة L1–L7، مصدر إلزامي) + prices بمصدر وثقة | PropertyValueTest ✓ |
| 2026-11-21 | D-05 | done | dataset_versions بـ SHA-256 لـ canonical JSON (نفس hash المحرك TS بالظبط) + calc_runs | DatasetVersionTest: hash PHP = hash TS ✓ |
| 2026-11-21 | D-06 | done | formulas + formula_versions + formula_lines، كل تعديل نسخة جديدة بـ hash وسبب | FormulaVersionTest ✓ |
| 2026-11-21 | D-10 | done | النسخة المعتمدة immutable (trigger + model guard) + diff بين أي نسختين | ApprovedFormulaImmutabilityTest ✓ — 74 اختبار Laravel كلهم ✓ |
| 2026-11-21 | F-04 | done | المادة الفعالة للخلطة + كمية الخام للهدف + ماء التخفيف | اختبارات SLES 70/LABSA 96/CAPB 30 (fixtures) + فحص يدوي |
| 2026-11-21 | F-05 | partial | معادلة السلفونيك من AV + نقاوة NaOH/KOH صلب أو محلول | فحص يدوي مني: 1000 جم AV 180 NaOH 99% ⇒ 129.615 جم ✓ — مستني مراجعة الكيميائي |
| 2026-11-21 | F-06 | done | التصبين NaOH/KOH بالنقاوة + نطاق SAP min/typ/max وأثره + 3 طرق للماء | soap.test.ts، water.test.ts |
| 2026-11-21 | S-02 | done | Num بأرقام tabular + مكونات مشتركة واحدة لكل القوائم: ListFilters/useListQuery/EntitySelect + ListQuery على السيرفر | ListQueryTest 16 ✓، e2e list-demo 6 ✓، evidence/S-02 |
| 2026-11-21 | S-01 | done | Taif Kit v1.8.0 + createKit + AppShell + RTL + ثيم من السيرفر + ملفات لغة ar/en هي المصدر الوحيد للنصوص + فحص i18n في lint | 102 اختبار Laravel ✓، e2e 12 ✓، evidence/S-01 |
| 2026-11-21 | J-03 | done | HiGHS 1.8.0 WASM مثبت + إعدادات حتمية (seed 0، thread واحد، simplex) | نفس الحل في 1000 تشغيلة |
| 2026-11-21 | J-01 | done | Tier Profile type + validator؛ القيم null = ناقصة وبتوقف الحل (مفيش profiles متعبية — J-02 بشري) | profile.test.ts |
| 2026-11-21 | J-04 | done | بناء الـ LP من profile + خامات بأسعار مؤرخة، والحل بيتحقق بـ Decimal بالظبط بعد التقريب | فحص يدوي مني: 50%/100% active ≥60 ⇒ 14 جنيه/كجم ✓ |
| 2026-11-21 | J-05 | done | tie-breaking lexicographic بالـ materialId ⇒ حل وحيد | 12 ترتيب مختلف ⇒ نفس الـ hash |
| 2026-11-21 | J-06 | done | Infeasibility: أقل مجموعة قيود متعارضة (deletion filter) كـ message keys + نصوص عربي في lang/optimizer.php | فحص يدوي: maxCost 12 ⇒ أقل تكلفة ممكنة 14، الفرق 2 ✓ |
| 2026-11-21 | J-07 | done | Sensitivity: duals + reduced costs + سيناريو تغيير سعر خامة بإعادة حل | S1 +10% ⇒ +1.8، +30% ⇒ تبديل لـ S2 |
| 2026-11-21 | J-08 | done | Reverse pricing من سعر الرف لأقصى تكلفة كيلو (Decimal، مفيش defaults) | 3 حالات يدوية |
| 2026-11-21 | F-12 | partial | مستند تقرير الدفعة الحتمي + reportHash (من غير timestamps جوه الـ hash) + verify؛ رسم الـ PDF في S-17 | 24 اختبار: نفس المدخلات ⇒ نفس الـ hash، أي تعديل ⇒ hash مختلف |
| 2026-11-21 | G-03 | partial | PR في Sanad (feat/manufacturing-api): item_kind/is_sellable، idempotency، tokens، قراءات، upsert، صرف/استلام إنتاج، أسعار جملة، ربط outbound من جهاز المصنع | 60 اختبار جديد ✓، نفس فشل البيئة قبل/بعد (131)؛ PR https://github.com/wasef201/sanad/pull/1 — مستني مراجعة صاحب المشروع |
| 2026-11-21 | G-10 | done | Sanad mock بنفس العقد: SanadFake لـ Http::fake + mock server على /__mock/sanad (local/testing بس) | MockSanadRoutesTest ✓ |
| 2026-11-21 | G-05 | done | مزامنة الأصناف: ربط صريح خامة ↔ منتج Sanad (مش بالاسم)، ونشر خامة جديدة كـ raw_material غير قابلة للبيع | SanadItemSyncTest ✓ |
| 2026-11-21 | G-04 | done | ناحية Formula Lab: أجهزة Sanad بـ tokens وصلاحيات، idempotency، POST /changes لـ cache قراءة بس، outbox + ack، وSanadClient لوضع الـ LAN | 249 اختبار Laravel ✓ — 9 اختلافات في العقد متسجلة للتوحيد |
| 2026-11-21 | K-07 | done | كمية الحمض/القلوي لـ pH مستهدف من منحنى معايرة مقاس (interpolation مشترك في core) | pH 6.6 ⇒ 0.15%، 500 جم ⇒ 0.75 جم |
| 2026-11-21 | L-03 | done | تخفيف كحول بمعادلة الكتلة من جدول OIML (مش حجوم) | 80% w/w ⇒ 40 row: 500+500 جم، حجم 1069.35 مل، والانكماش ظاهر |
| 2026-11-21 | L-02 | done | حاسبة عطر: زيت + مثبت + كحول بتركيز مستهدف + ماء، وفحص IFRA (PASS/FAIL/UNKNOWN) | فحص يدوي مني: 1000 جم، 20% زيت ⇒ 395/395 جم، إيثانول 31.6% w/w ✓ |
| 2026-11-21 | K-03 | done | الكلور الفعال من COA + تخفيف + فقد تخزين من قياسات المستخدم بس | فحص يدوي: 12.5%⇒5% = 400 جم، فقد 8% ⇒ 434.78 جم ✓ |
| 2026-11-21 | H-07 | done | فحص الحجم الاسمي ≤ سعة العبوة − headspace | 530 مل/5% ⇒ +3.5 PASS، 520 ⇒ −6 FAIL ✓ |
| 2026-11-21 | G-03 | done | صاحب المشروع عمل merge لـ wasef201/sanad#1 | — |
| 2026-11-21 | N-01 | done | Rules DSL بمصدر إلزامي + hash لكل rule set + evaluator (PASS/FAIL/UNKNOWN، مجموع نسب Annex III) | dsl.test.ts، evaluate.test.ts |
| 2026-11-21 | N-05 | done | داتا ناقصة ⇒ UNKNOWN، عمرها ما تبقى PASS (property-based) | compliance.prop.test.ts |
| 2026-11-21 | N-06 | done | قائمة INCI تنازلي + حد 1% + دمج المكرر + تحذيرات البطاقة | inci.test.ts |
| 2026-11-21 | N-09 | partial | قاعدة الميثانول كـ rule data في draft set لحد ما المصدر التنظيمي يتحدد | methanol-rule.test.ts؛ المصدر في HUMAN-TODO |
| 2026-11-21 | N-04 | done | فحص IFRA لكل زيت عطر قصاد شهادته وفئة المنتج | ifra.test.ts |
| 2026-11-21 | L-09 | partial | نفس الفحص لكل فئة منتج — مستني داتا الفئات (N-10) | — |
| 2026-11-21 | S-03 | done | مكتبة الخامات (فلاتر كاملة، تفاصيل بخواص ومصادر واختيار المحرك والتعارضات، أسعار، سجل) + قائمة التركيبات + المحرر (Σ لحظي، q.s.، تكلفة لحظية من المحرك، نسخ بسبب، مقارنة نسخ) | 348 اختبار Laravel ✓، e2e 28 ✓، evidence/S-03 (16 صورة) |
| 2026-11-21 | D-09 | done | خامة من المحرر = draft وسعرها L7 ⇒ التكلفة 'غير موثوقة' ظاهرة | MaterialsTest/FormulaEditorTest + screenshot |
| 2026-11-21 | H-03/H-05/H-08 | done | محرك: تكلفة BOM التغليف للوحدة (مرفوض، كرتونة/بالتة)، توزيع التكاليف لمرة واحدة، تقرير نصيب التغليف | packaging.test.ts (12) |
| 2026-11-21 | I-05 | done | سلّم السعر للأمام والخلف (تنفيذ واحد في المحرك، المُحسِّن بيستخدمه) + ضريبة مؤرخة من الإدخال | pricing.test.ts (16) + property test round-trip |
| 2026-11-21 | I-01/02/03/06/07 | done | التكلفة الكاملة للوحدة بكل البنود ومصادرها + تحويل + جودة وتنظيم + حساسية الدولار + مقارنة أحجام SKU | فحص يدوي: 10+6+1.145+0.34+0.07+0.03 = 17.585 ✓ |
| 2026-11-21 | Y-01 | done | Decision DSL بنسخة وhash؛ حقل ناقص ⇒ منع (مش نجاح)؛ helpers مشتركة مع المطابقة في core | 71 اختبار decision |
| 2026-11-21 | Y-02/Y-03/Y-05 | partial | قواعد اعتماد التركيبة وإطلاق الدفعة والنشر لـ Sanad (draft، مستنية مراجعة صاحب المشروع + حد تغيّر السعر) | اختبارات pass/fail/missing لكل rule set |
| 2026-11-21 | Y-06 | done | سجل القرار: decisionHash (مدخلات+قواعد+نتيجة) ثابت، recordHash بكل حاجة، override بيحفظ الأسباب الأصلية + verify replay | record tests |
| 2026-11-21 | S-04/S-07/S-14 | done | لوحة 'الرقم ده جه منين' (شجرة الحساب + المصدر + الثقة) + 6 حاسبات مستقلة + حاسبة العطور (OIML + IFRA) + سجل خواص موحد مترجم | 380 اختبار Laravel، e2e 49 ✓ بقيم محسوبة يدوي، evidence/S-04,S-07,S-14 |
| 2026-11-21 | FX | done | تعدد العملات والجنيه أساس: أسعار صرف مؤرخة بمصدر (مفيش افتراضي)، كل سعر بعملته ويتحول بسعر يوم السعر، عملة عرض اختيارية، نسبة الارتباط بالدولار في المحرر، FX في لوحة الشرح | 455 PHPUnit، محرك 667، e2e 59 ✓؛ evidence/FX |
| 2026-11-21 | S-TOPNAV | done | Formula Lab على Taif Kit 1.9.0 (ce11a4a) + اختيار القايمة جنب/فوق محفوظ على السيرفر، نفس تعريف القايمة | 461 PHPUnit ✓، e2e 68 ✓؛ evidence/S-TOPNAV |
| 2026-11-21 | قرار | — | صاحب المشروع: مفيش solver لجدولة الإنتاج (CP-SAT/OR-Tools) ولا تخطيط دفعات MIP دلوقتي؛ نفضل في البسيط والفلو الأساسي. HiGHS يفضل للفئات بس | — |
| 2026-11-21 | S-06 | done | شاشة المطابقة: مجموعات قواعد بمصدر ونشر immutable بـ hash (تحقق من المحرك على السيرفر عبر EngineRunner)، تركيب الخامات، لوحة المطابقة في المحرر PASS/FAIL/UNKNOWN، IFRA لكل زيت، صفحة INCI/البطاقة | 565 PHPUnit، e2e 80 ✓؛ فحص يدوي: 10%×4%=0.4 PASS، ×6%=0.6 FAIL ✓؛ evidence/S-06 |
| 2026-11-21 | قرارات | — | صاحب المشروع: (1) دقة Sanad خانتين كفاية، (2) حد تغيّر السعر لإعادة الاعتماد 5%، (3) قواعد القرارات Y-02/Y-03/Y-05 معتمدة، (4) مفيش حذف لسعر الصرف، (5) أسعار الصرف يدوي، الضريبة لكل منتج: تفعيل/إيقاف و0% أو 14%، أسعار لكل منتج ولكل تاجر (price lists في Sanad)، دمج الكيت 1.9.0 (اتدمج في main)، تنفيذ تحسينات الكيت | — |
| 2026-11-21 | TAX | done | تنفيذ قرارات صاحب المشروع: (1) ضريبة لكل منتج: معطّلة (بدون سطر ضريبة) / 0% / 14% من config/tax.php، نظام ضريبي مؤرخ بمصدر (قرار صاحب المشروع، L6، بانتظار التحقق القانوني)، في المحرك وسلّم السعر والمحرر وحمولة نشر المنتج لـ Sanad (tax_rate: null للمعطّلة، "0.00"، "14.00")؛ (2) قواعد القرارات الثلاث published بموافقة owner بتاريخ 2026-11-21 وhash مثبّت، وحد إعادة الاعتماد 5% من config/decisions.php، ولغة ar/en؛ (3) خريطة خواص الصابون SoapCalc (L6_community) كمصدر قابل للاختيار في حاسبة التصبن | محرك 691 اختبار (14% على 100.00 = 14/114، 0% = سطر صفر، معطّلة = بدون سطر)، 600 PHPUnit، e2e ✓؛ evidence/TAX |
| 2026-11-21 | KIT-1 | done | ترقية Taif Kit لـ 1.10.0 (9ce3fd2): عملة العرض جوه قايمة العرض (AppShell #theme-extra)، TraceTree على TreeView #item، فلاتر الأرقام والعدّاد ومسح الكل من FilterBar (اتشال كود ListFilters/useListQuery المخصص ومفاتيح lang)، StatCard بـ reason، defineLabels. SourcedInput فضل الـ Select تحت القيمة (العمود ضيق) | lint+i18n، typecheck، 681+61 Vitest، 600 PHPUnit، e2e 91 ✓ | orchestration/evidence/KIT-1.10/ |
| 2026-11-21 | O-01 | done | تجربة معملية: نسخة تركيبة + دفعة (حجم/وحدة/تاريخ/القائم بها) + قياسات (pH، كثافة، لزوجة، نقطة تعكر، رغوة، ثبات 4 أسابيع) كل قياس بقيمة decimal نصية ووحدة وطريقة/بروتوكول وجهاز ووقت وثقة L1؛ ناقص = مفيش صف (مش 0)؛ append-only بـ trigger + audit_log؛ تجربة لنسخة معتمدة مسموحة؛ قائمة بفلاتر URL وفورم إدخال وتفاصيل ورابط من المحرر | 672 PHPUnit، e2e lab ✓؛ evidence/O-lab-loop |
| 2026-11-21 | O-02 | done | draft → lab_testing → approved/rejected بتوقيع append-only (مستخدم، وقت، تعليق، hash النسخة، أساس القرار)؛ الاعتماد مرفوض من غير تجربة مكتملة فيها المطلوب (config/lab.php)؛ الرفض بسبب إجباري؛ المعتمد immutable (model + trigger)؛ شرط AFV-06 فقط من قاعدة approve-formula-version (الباقي مش موصّل بعد، HUMAN-TODO) | FormulaWorkflowTest، e2e ✓ |
| 2026-11-21 | O-06 | done | قوالب بروتوكولات (pH، كثافة بيكنومتر، لزوجة Brookfield) كداتا من config/lab.php بـ seeder: خطوات + الجهاز + الوحدة، من غير حدود قبول؛ صفحة قائمة وتفاصيل | LabProtocolTest، e2e ✓ |
