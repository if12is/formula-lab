import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, setTheme, settle, stamp } from './support/kit';
import { E2E_PASSWORD, signInAsQualityManager } from './support/sign-in';

/**
 * Y-08 "why was the decision blocked" and Y-04 override, end to end, in Arabic RTL, light and dark, desktop and a 390px phone.
 *   approval panel (lab testing, no completed trial): the shared decision card lists the failed condition (AFV-06) with the step to fix it;
 *   publish page, cost not approved: the SAME card lists every failed condition (PS-01 and PS-02) each with its required step, and
 *     with nobody signed in it says why the override is unavailable;
 *   publish page, a price moved past the limit (PS-04): a signed-in quality manager overrides it (reason required, wrong password
 *     refused, correct password signs), the override is shown and recorded, and the publish then goes through.
 * Hand-computed (TEST-FIXTURE numbers, CostingDemoSeeder): approved prices A 10 and B 25 EGP/kg; a rise of A to 10.6 is 6 %, over the 5 % limit.
 * Screenshots go to orchestration/evidence/Y-governance/.
 */
const OUT = 'orchestration/evidence/Y-governance';
const artisan = (command: string, env: Record<string, string> = {}): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8', env: { ...process.env, ...env } });

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('decision card and override', async ({ page, context }) => {
        test.setTimeout(180_000);
        const run = `${stamp()}-${theme}-${device}`;
        const code = `E2E-GOV-${run}`;
        const shot = async (name: string, testId?: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          const path = `${OUT}/${name}-${theme}-${device}.png`;
          if (testId) {
            await page.getByTestId(testId).screenshot({ path });
          } else {
            await page.screenshot({ path, fullPage: true });
          }
        };
        artisan('db:seed --class=LabDemoSeeder --force');
        artisan('db:seed --class=CostingDemoSeeder --force', { COSTING_DEMO_CODE: code });

        // ---- Y-08 on the approval panel ---------------------------------------------------------------------------------
        await page.goto('/formulas?search=DEMO-LAB-BLOCKED');
        await page.getByText('DEMO-LAB-BLOCKED').first().click();
        const blocked = page.getByTestId('workflow-blocked');
        await expect(blocked).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(blocked).toHaveAttribute('data-state', 'blocked');
        await expect(blocked.locator('[data-rule="AFV-06"]')).toContainText('نتائج معملية مطلوبة ناقصة');
        await expect(blocked.locator('[data-rule="AFV-06"]')).toContainText('الخطوة المطلوبة');
        await shot('approval-blocked-card', 'workflow-blocked');

        // ---- Y-08 on the publish page: every failed condition, each with its step ----------------------------------------
        const id = artisan(`tinker --execute='echo App\\Models\\Formula::query()->where("code", "${code}")->value("id");'`).trim();
        await page.goto(`/formulas/${id}/publish`);
        const card = page.getByTestId('publish-decision');
        await expect(card).toHaveAttribute('data-state', 'blocked');
        const reasons = card.getByTestId('decision-reasons').locator('li');
        await expect(reasons).toHaveCount(2);
        await expect(card.locator('[data-rule="PS-01"]')).toContainText('التكلفة لم تُعتمد بعد');
        await expect(card.locator('[data-rule="PS-01"]')).toContainText('الخطوة المطلوبة');
        await expect(card.locator('[data-rule="PS-02"]')).toContainText('سعر البيع');
        // Nobody can sign in yet: the card says why the override is not offered.
        await expect(card.getByTestId('override-unavailable')).toContainText('الدخول لسه مش متاح');
        await expect(card.getByTestId('override-open')).toHaveCount(0);
        await shot('publish-blocked-every-condition', 'publish-decision');

        // ---- Y-04: a price rise past the limit, overridden by a quality manager --------------------------------------------
        artisan(`tinker --execute='$f = App\\Models\\Formula::query()->findOrFail("${id}"); app(App\\Services\\Costing\\CostApprovalService::class)->approve($f, null, null); App\\Models\\Price::factory()->create(["trade_material_id" => App\\Models\\TradeMaterial::query()->where("trade_name", "Demo Cost Base ${code}")->value("id"), "amount" => "10.6", "quoted_on" => "2026-11-20", "confidence" => "L1_coa_batch", "source_id" => App\\Models\\Source::query()->where("title", "INV-2026-0042")->value("id")]);'`);
        await signInAsQualityManager(context, run);
        await page.goto(`/formulas/${id}/publish`);
        await page.getByTestId('publish-form').getByLabel(/^سعر البيع/).fill('39');
        await expect(card.locator('[data-rule="PS-04"]')).toContainText('إعادة اعتماد');
        await expect(card.locator('[data-rule="PS-02"]')).toHaveCount(0);
        await expect(page.getByTestId('publish-submit')).toBeDisabled();
        await expect(card.getByTestId('override-open')).toBeVisible();
        await shot('publish-blocked-override-offered', 'publish-decision');

        await card.getByTestId('override-open').click();
        const dialog = page.getByRole('dialog');
        await dialog.getByTestId('override-confirm').click();
        await expect(dialog).toContainText('حقل السبب مطلوب');
        await shot('override-modal-errors');
        await dialog.getByTestId('override-reason').fill('Trial batch for the distributor, price rise reviewed');
        await dialog.getByTestId('override-password').fill('wrong-password');
        await dialog.getByTestId('override-confirm').click();
        await expect(dialog).toContainText('كلمة السر غلط');
        await dialog.getByTestId('override-password').fill(E2E_PASSWORD);
        await dialog.getByTestId('override-confirm').click();
        await expect(page.getByText('التجاوز اتوقّع واتسجل')).toBeVisible();

        const active = card.getByTestId('override-active');
        await expect(active).toContainText('Trial batch for the distributor');
        await expect(active).toContainText('quality_manager');
        await expect(card.locator('[data-rule="PS-04"]')).toBeVisible();
        await expect(page.getByTestId('publish-submit')).toBeEnabled();
        await shot('publish-overridden', 'publish-decision');

        await page.getByTestId('publish-submit').click();
        await expect(page.getByText('اتحط في طابور Sanad')).toBeVisible();
        await expect(page.getByTestId('publish-history')).toContainText(code);
        await shot('publish-done-after-override');

        const stored = JSON.parse(artisan(`tinker --execute='$o = App\\Models\\DecisionOverride::query()->where("subject_ref", "${id}")->sole(); $p = App\\Models\\FormulaPublication::query()->where("formula_id", "${id}")->latest("published_at")->first(); echo json_encode(["result" => $o->record["result"], "original" => $o->record["originalDecision"], "failed" => array_column($o->record["reasons"], "ruleId"), "role" => $o->role, "ref" => $o->record["override"]["signatureRef"] === $o->id, "linked" => $p->decision_override_id === $o->id]);'`).trim());
        expect(stored).toEqual({ result: 'overridden', original: 'blocked', failed: ['PS-04'], role: 'quality_manager', ref: true, linked: true });
      });
    });
  }
}
