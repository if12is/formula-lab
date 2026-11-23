import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_REFERENCE, DEMO_SOURCE, expectRtlAndFits, fillSourced, pickOption, pickRecord, seedFormulationDemo, setTheme, settle, stamp } from './support/kit';

/**
 * Review screenshots of M-06 to M-09 and H-02 (skincare templates and presets, stability protocols, ionic emulsifier paths and the
 * ionicity / required-HLB confidence filters, closure compatibility), in light and dark, on a desktop and a 390px phone, at
 * deviceScaleFactor 2. Saved to orchestration/evidence/M-skincare-rest/. Every shot asserts the page is RTL and does not scroll sideways.
 */
test.describe.configure({ mode: 'serial' });

const suffix = stamp();
const quat = `Evidence Quat ${suffix}`;
const bottle = `Evidence Bottle ${suffix}`;
const pump = `Evidence Pump ${suffix}`;
const cap = `Evidence Cap ${suffix}`;
const finish = `E${suffix.toUpperCase()}`;

test.beforeAll(() => {
  seedFormulationDemo();
  execSync('php artisan db:seed --class=FamilyDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
  execSync(
    `php artisan tinker --execute='$s = App\\Models\\Source::query()->firstOrFail(); $q = App\\Models\\TradeMaterial::query()->create(["trade_name" => "${quat}", "status" => "active", "is_mixture" => true, "ionicity" => "cationic"]); App\\Models\\PropertyValue::query()->create(["entity_type" => "trade_material", "entity_id" => $q->id, "property" => "required_hlb", "value" => "10", "unit" => "HLB", "source_id" => $s->id, "confidence" => "L7_estimate", "valid_from" => "2026-01-01"]); foreach ([["${bottle}", "container", "${finish}"], ["${pump}", "pump_sprayer", "${finish}-P"], ["${cap}", "closure", "${finish}-X"]] as [$n, $k, $f]) { App\\Models\\TradeMaterial::query()->create(["trade_name" => $n, "item_kind" => $k, "neck_finish" => $f, "status" => "active", "packaging_source_id" => $s->id, "packaging_confidence" => "L2_supplier_tds"]); } App\\Models\\PackagingCompatibility::query()->create(["container_neck_finish" => "${finish}", "closure_neck_finish" => "${finish}-P", "source_id" => $s->id, "confidence" => "L3_standard", "note" => "Supplier drawing"]);'`,
    { cwd: 'apps/api', stdio: 'pipe' },
  );
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1100 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        test.setTimeout(180_000);
        const shot = async (name: string, target: Page = page): Promise<void> => {
          await settle(target);
          await expectRtlAndFits(target);
          await target.screenshot({ path: `orchestration/evidence/M-skincare-rest/${name}-${theme}-${device}.png` });
        };
        const scrollTop = () => page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));

        // M-08: the library with the ionic character, the required HLB confidence and both filters.
        await page.goto(`/materials?search=${encodeURIComponent(quat)}&ionicity=cationic&required_hlb_confidence=L7_estimate`);
        await expect(page.getByText(quat).first()).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await shot('materials-ionicity-filters');

        await page.getByText(quat).first().click();
        await expect(page.getByRole('heading', { level: 1, name: quat })).toBeVisible();
        await expect(page.getByText('كاتيوني (موجب)').first()).toBeVisible();
        await settle(page);
        await page.getByRole('button', { name: 'تعديل' }).click();
        await expect(page.getByRole('dialog').getByTestId('ionicity')).toBeVisible();
        await shot('material-edit-ionicity');
        await page.keyboard.press('Escape');

        // M-08: the cationic path (above the TDS range by 1.25) and the same range refused as an L7 estimate.
        await page.goto('/calculators/hlb');
        await page.getByRole('button', { name: 'مستحلب كاتيوني (نطاق TDS)' }).click();
        await page.getByLabel('الجرعة المستخدمة', { exact: true }).fill('6.25');
        await fillSourced(page, 'نطاق TDS: الحد الأدنى', '2', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await fillSourced(page, 'نطاق TDS: الحد الأقصى', '5', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await expect(page.locator('[data-result="tds_above"]')).toContainText('1.250');
        await scrollTop();
        await shot('hlb-cationic');
        if (device === 'mobile') {
          await page.getByTestId('results-card').scrollIntoViewIfNeeded();
          await shot('hlb-cationic-results');
        }
        await pickOption(page, page.getByRole('group', { name: 'نطاق TDS: الحد الأدنى' }).getByRole('combobox', { name: /^ثقة/ }), /تقدير/);
        await expect(page.getByTestId('missing')).toContainText(/تقدير \(L7\) فقط/);
        await page.getByTestId('missing').scrollIntoViewIfNeeded();
        await shot('hlb-cationic-l7-refused');

        // M-08: the anionic path, 120 g at 28% in 1000 g.
        await page.goto('/calculators/hlb');
        await page.getByRole('button', { name: 'سطحي أنيوني (مادة فعالة)' }).click();
        await page.getByLabel('كتلة الخامة', { exact: true }).fill('120');
        await fillSourced(page, 'المادة الفعالة في الخامة', '28', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await page.getByLabel('الكتلة الكلية للتركيبة (اختياري)', { exact: true }).fill('1000');
        await expect(page.locator('[data-result="active_percent"]')).toContainText('3.360');
        await scrollTop();
        await shot('hlb-anionic');
        if (device === 'mobile') {
          await page.getByTestId('results-card').scrollIntoViewIfNeeded();
          await shot('hlb-anionic-results');
        }

        // M-07: the stability protocols.
        await page.goto('/lab/protocols?search=stability');
        await expect(page.getByText('ثبات المستحلب بالطرد المركزي').first()).toBeVisible();
        await shot('stability-protocols');
        await page.goto('/lab/protocols?search=stability_40c');
        await page.getByText('الثبات لمدة 4 أسابيع عند 40 °C').first().click();
        await expect(page.getByTestId('protocol-steps').locator('li')).toHaveCount(5);
        await scrollTop();
        await shot('stability-protocol-40c');

        // M-09: the skincare presets, and M-06: a template with no share filled in.
        await page.goto('/families?group=skincare&per=100');
        await expect(page.getByText('كريم وش').first()).toBeVisible();
        await shot('skincare-families');
        await page.goto('/families/face_cream');
        await expect(page.getByTestId('family-facts')).toContainText('5B');
        await shot('family-face-cream');
        await page.goto('/families/sunscreen');
        await shot('family-sunscreen-unsourced');
        await page.goto('/templates/face_cream_basic');
        await expect(page.getByTestId('template-lines')).toContainText('غير متوفرة');
        await shot('template-face-cream');
        await page.goto('/templates/body_butter_basic');
        await shot('template-body-butter');

        // H-02: the declared pairs and the BOM check, refused and passed.
        await page.goto(`/packaging/compatibility?search=${encodeURIComponent(finish)}`);
        await expect(page.getByText(finish).first()).toBeVisible();
        await pickRecord(page, page.getByTestId('check-container'), bottle);
        await pickRecord(page, page.getByTestId('check-closure'), cap);
        await page.getByTestId('check-run').click();
        await expect(page.getByTestId('check-result')).toHaveAttribute('data-status', 'FAIL');
        await scrollTop();
        await shot('compat-refused');
        await pickRecord(page, page.getByTestId('check-closure'), pump);
        await page.getByTestId('check-run').click();
        await expect(page.getByTestId('check-result')).toHaveAttribute('data-status', 'PASS');
        await shot('compat-passed');
        await page.getByTestId('compat-new').click();
        await expect(page.getByRole('dialog').getByTestId('compat-form')).toBeVisible();
        await shot('compat-declare-form');
        await page.keyboard.press('Escape');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
