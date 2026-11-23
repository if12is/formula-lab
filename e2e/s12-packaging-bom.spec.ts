import { execSync } from 'node:child_process';
import { deflateSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { CONFIDENCE_TDS, DEMO_SOURCE, expectRtlAndFits, pickOption, pickRecord, seedFormulationDemo, setTheme, settle, stamp } from './support/kit';

/**
 * S-12: the packaging BOM editor of a SKU, end to end, in Arabic RTL, light and dark, on a desktop and a 390px phone. Each combination runs
 * the whole journey on a SKU, components and neck finishes of its own (a stamped name), so runs never share a row or a declared pair:
 *   create the SKU with its carton packing; add the bottle, the closure, the carton share and a label; a figure without its source is
 *   refused; a label with no figures shows the cost as incomplete (every gap listed, no total); filling it gives the engine's total;
 *   the pack image is uploaded (a real PNG shown from the private disk) and removed; the list filters live in the URL.
 * Hand-computed (TEST-FIXTURE numbers): bottle 3 / (1 - 0.20) = 3.75, cap 1, carton 24 / 12 units = 2, label 0.5: 3.75 + 1 + 2 + 0.5 = 7.25 EGP.
 * Screenshots go to orchestration/evidence/S-12/.
 */
const OUT = 'orchestration/evidence/S-12';

/** A small valid PNG (a diagonal colour wash), built here so the spec needs no binary fixture. */
function png(size = 160): Buffer {
  const crcTable = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k += 1) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    return c >>> 0;
  });
  const crc = (buffer: Buffer): number => {
    let c = 0xffffffff;
    for (const byte of buffer) {
      c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
    }
    return (c ^ 0xffffffff) >>> 0;
  };
  const chunk = (type: string, data: Buffer): Buffer => {
    const body = Buffer.concat([Buffer.from(type), data]);
    const out = Buffer.alloc(body.length + 8);
    out.writeUInt32BE(data.length, 0);
    body.copy(out, 4);
    out.writeUInt32BE(crc(body), body.length + 4);
    return out;
  };
  const header = Buffer.alloc(13);
  header.writeUInt32BE(size, 0);
  header.writeUInt32BE(size, 4);
  header.set([8, 2, 0, 0, 0], 8);
  const rows = Buffer.alloc((size * 3 + 1) * size);
  for (let y = 0; y < size; y += 1) {
    const row = y * (size * 3 + 1);
    for (let x = 0; x < size; x += 1) {
      rows.set([40 + Math.floor((x * 180) / size), 90 + Math.floor((y * 120) / size), 200 - Math.floor(((x + y) * 100) / (2 * size))], row + 1 + x * 3);
    }
  }
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2, actionTimeout: 15_000 });

      test('the packaging BOM of a SKU', async ({ page }) => {
        test.setTimeout(240_000);
        const run = `${stamp()}${theme[0]}${device[0]}`;
        const sku = `Evidence SKU ${run}`;
        const bottle = `Evidence bottle ${run}`;
        const cap = `Evidence cap ${run}`;
        const carton = `Evidence carton ${run}`;
        const label = `Evidence label ${run}`;
        const finish = `S12-${run.toUpperCase()}`;
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expectRtlAndFits(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        seedFormulationDemo();
        execSync(
          `php artisan tinker --execute='$s = App\\Models\\Source::query()->where("title", "Demo supplier data sheet")->firstOrFail(); foreach ([["${bottle}", "container", "${finish}"], ["${cap}", "closure", "${finish}-P"], ["${carton}", "shipping_carton", null], ["${label}", "label", null]] as [$n, $k, $f]) { App\\Models\\TradeMaterial::query()->create(["trade_name" => $n, "item_kind" => $k, "neck_finish" => $f, "status" => "active"] + ($f === null ? [] : ["packaging_source_id" => $s->id, "packaging_confidence" => "L2_supplier_tds"])); } App\\Models\\PackagingCompatibility::query()->create(["container_neck_finish" => "${finish}", "closure_neck_finish" => "${finish}-P", "source_id" => $s->id, "confidence" => "L3_standard", "note" => "Supplier drawing"]);'`,
          { cwd: 'apps/api', stdio: 'pipe' },
        );

        // The list is reachable from the packaging library; a SKU is created with its carton packing.
        await page.goto('/packaging');
        await page.getByTestId('packaging-bom-link').click();
        await expect(page.getByRole('heading', { level: 1, name: 'BOM التغليف لكل SKU' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await page.getByTestId('bom-new').click();
        const form = page.getByTestId('bom-form');
        await page.getByRole('dialog').getByTestId('bom-submit').click();
        await expect(form).toContainText('اسم الـ SKU');
        await form.getByTestId('bom-name').fill(sku);
        await form.getByTestId('bom-sku-code').fill(`SKU-${run}`);
        await form.getByLabel(/^عدد الوحدات في الكرتونة/).fill('12');
        await shot('bom-form');
        await page.getByRole('dialog').getByTestId('bom-submit').click();
        await expect(page.getByRole('heading', { level: 1, name: sku })).toBeVisible();
        await expect(page.getByText('تم إنشاء BOM التغليف. أضف مكوّناته.')).toBeVisible();
        await expect(page.getByTestId('bom-cost-empty')).toBeVisible();
        await shot('bom-empty');

        const addLine = async (component: string, figures: { level?: string; qty?: string; reject?: string; price?: string } | null): Promise<void> => {
          await page.getByTestId('bom-line-add').click();
          const dialog = page.getByRole('dialog');
          await pickRecord(page, dialog.getByRole('combobox', { name: /^المكوّن/ }), component);
          if (figures?.level) {
            await pickOption(page, dialog.getByRole('combobox', { name: /^المستوى/ }), figures.level);
          }
          if (figures) {
            await dialog.getByLabel(/^الكمية لكل مستوى/).fill(figures.qty ?? '1');
            await dialog.getByLabel(/^نسبة المرفوض والكسر/).fill(figures.reject ?? '0');
            await dialog.getByLabel(/^سعر القطعة \(جنيه\)/).fill(figures.price ?? '');
            await pickRecord(page, dialog.getByRole('combobox', { name: /^مصدر الأرقام/ }), DEMO_SOURCE);
            await pickOption(page, dialog.getByRole('combobox', { name: /^درجة الثقة/ }), CONFIDENCE_TDS);
          }
          await dialog.getByTestId('bom-line-submit').click();
          await expect(dialog).toHaveCount(0);
        };

        // A figure typed without its source is refused, with the way to fix it.
        await page.getByTestId('bom-line-add').click();
        const dialog = page.getByRole('dialog');
        await pickRecord(page, dialog.getByRole('combobox', { name: /^المكوّن/ }), bottle);
        await dialog.getByLabel(/^سعر القطعة \(جنيه\)/).fill('3');
        await dialog.getByTestId('bom-line-submit').click();
        await expect(dialog).toContainText('الرقم محتاج مصدره ودرجة ثقته.');
        await shot('bom-line-source-required');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);

        // The bottle alone: 3 / (1 - 0.20) = 3.75 from the engine. The closure fits through the declared pair.
        await addLine(bottle, { qty: '1', reject: '20', price: '3' });
        await expect(page.getByTestId('bom-cost-total')).toContainText('3.7500');
        await addLine(cap, { price: '1' });
        await expect(page.getByTestId('bom-cost-total')).toContainText('4.7500');

        // A carton share needs the packing (12 units per carton, set above): 24 / 12 = 2.
        await addLine(carton, { level: 'كرتونة الشحن', price: '24' });
        await expect(page.getByTestId('bom-cost-total')).toContainText('6.7500');

        // A label with no figure: the cost is incomplete, every gap is listed, there is no total and no zero.
        await addLine(label, null);
        await expect(page.getByTestId('bom-cost-incomplete')).toBeVisible();
        await expect(page.getByTestId('bom-cost-total')).toHaveCount(0);
        const gaps = page.getByTestId('missing-item');
        await expect(gaps).toHaveCount(3);
        await expect(page.getByTestId('bom-cost-incomplete')).toContainText(label);
        await expect(page.getByTestId('bom-cost-incomplete')).toContainText('سعر القطعة');
        await shot('bom-cost-incomplete');

        // Filling it in gives the total: 3.75 + 1 + 2 + 0.5 = 7.25.
        const row = page.getByTestId(/^bom-line-edit-/).last();
        // On a desktop the row's actions appear on hover (the kit's table), so the click hovers the row first.
        await row.locator('xpath=ancestor::tr').hover({ timeout: 2_000 }).catch(() => undefined);
        await row.click();
        const edit = page.getByRole('dialog');
        await edit.getByLabel(/^الكمية لكل مستوى/).fill('1');
        await edit.getByLabel(/^نسبة المرفوض والكسر/).fill('0');
        await edit.getByLabel(/^سعر القطعة \(جنيه\)/).fill('0.5');
        await pickRecord(page, edit.getByRole('combobox', { name: /^مصدر الأرقام/ }), DEMO_SOURCE);
        await pickOption(page, edit.getByRole('combobox', { name: /^درجة الثقة/ }), CONFIDENCE_TDS);
        await edit.getByTestId('bom-line-submit').click();
        await expect(page.getByTestId('bom-cost-total')).toContainText('7.2500');
        await expect(page.getByTestId('bom-cost')).toHaveAttribute('data-state', 'ok');
        await shot('bom-cost-complete');

        // The cost explains itself from the engine's trace (desktop light is enough to prove it).
        if (theme === 'light' && device === 'desktop') {
          await page.getByTestId('bom-cost-total').getByTestId('explain-trigger').click();
          await expect(page.getByRole('dialog')).toContainText(bottle);
          await shot('bom-cost-explain');
          await page.keyboard.press('Escape');
        }

        // The pack image: a real PNG is stored on the private disk and shown from the app, then removed.
        await expect(page.getByTestId('bom-image')).toHaveCount(0);
        await page.getByTestId('bom-image-upload').click();
        await page.getByTestId('bom-image-input').locator('input[type="file"]').setInputFiles({ name: 'pack.png', mimeType: 'image/png', buffer: png() });
        await page.getByTestId('bom-image-submit').click();
        await expect(page.getByTestId('bom-image')).toBeVisible();
        await expect.poll(() => page.getByTestId('bom-image').evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth)).toBe(160);
        await page.getByTestId('bom-image').scrollIntoViewIfNeeded();
        await shot('bom-image');

        // The list: the SKU is found by search, the image filter keeps it, a filter that matches nothing offers a way back.
        await page.goto(`/packaging/boms?search=${encodeURIComponent(run)}`);
        await expect(page.getByText(sku).first()).toBeVisible();
        await shot('bom-list');
        await page.goto(`/packaging/boms?search=${encodeURIComponent(run)}&has_image=1`);
        await expect(page.getByText(sku).first()).toBeVisible();
        await page.goto(`/packaging/boms?search=${encodeURIComponent(run)}&has_image=0`);
        await expect(page.getByText(sku)).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'مسح كل الفلاتر' }).first()).toBeVisible();
        await shot('bom-list-filtered-empty');

        // Removing a line asks first, and the cost follows; removing the image asks first too.
        await page.goto(`/packaging/boms?search=${encodeURIComponent(run)}`);
        await page.getByText(sku).first().click();
        await page.getByTestId(/^bom-line-remove-/).last().locator('xpath=ancestor::*[self::tr or @data-mobile-row][1]').hover({ timeout: 2_000 }).catch(() => undefined);
        await page.getByTestId(/^bom-line-remove-/).last().click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'حذف' }).click();
        await expect(page.getByTestId('bom-cost-total')).toContainText('6.7500', { timeout: 20_000 });
        await page.getByTestId('bom-image-remove').click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'حذف الصورة' }).click();
        await expect(page.getByTestId('bom-image')).toHaveCount(0);
        await shot('bom-after-removals');
      });
    });
  }
}
