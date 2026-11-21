# المرحلة G: الربط مع Sanad POS + التكلفة الواصلة

**الهدف:** ما نعيدش اختراع العجلة: الموردين والمشتريات والمخزون والمبيعات والعملاء والموزعين والفواتير في Sanad. برنامج التركيبات بيقرا ويكتب عن طريق API، وبيضيف بس اللي Sanad ماعندوش (landed cost، COA، تركيبات، تكلفة).

**تعتمد على:** D، C

## شروط الخروج (Exit criteria)
- [ ] عقد API موثّق بين Formula Lab وSanad (02-specs/sanad-integration.md)
- [ ] Client في Laravel مع retries وidempotency
- [ ] مزامنة الأصناف والموردين والمشتريات من Sanad
- [ ] Landed cost كطبقة فوق مشتريات Sanad

## التاسكات
شوف `tasks.md` (10 تاسك).
