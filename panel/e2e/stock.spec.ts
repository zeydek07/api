import { expect, test } from '@playwright/test';
import { USERS, collectErrors } from './helpers';

test.describe('Stok (yönetici)', () => {
  test.use({ storageState: USERS.admin.state });

  test('karekod okutarak lotlu stok girişi yapılır ve listede görünür', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/stok');
    await page.getByRole('button', { name: /Stok girişi/ }).click();
    await page.locator('#op-wh').selectOption({ label: 'Merkez Depo' });

    // Örnek veride MED-001 "Steril Gazlı Bez 10x10 cm" ürününün GTIN'i 08690100000000
    const scan = page.locator('#op-scan');
    for (let i = 0; i < 2; i++) {
      await scan.fill('(01)08690100000000(17)301231(10)E2E-LOT-1');
      await scan.press('Enter');
    }
    await expect(page.getByText('✓ Steril Gazlı Bez 10x10 cm · LOT E2E-LOT-1 · SKT 2030-12-31')).toBeVisible();
    // Aynı lot iki kez okutuldu: tek satır, miktar 2
    await expect(page.locator('app-modal input[aria-label="Miktar"]').first()).toHaveValue('2');
    await expect(page.locator('app-product-picker input').first()).toHaveValue('Steril Gazlı Bez 10x10 cm');

    await page.getByRole('button', { name: /Girişi kaydet/ }).click();
    await expect(page.getByText('Stok girişi yapıldı.')).toBeVisible();

    await page.getByRole('textbox', { name: 'Ara' }).fill('E2E-LOT-1');
    const row = page.locator('tbody tr').first();
    await expect(row).toContainText('Steril Gazlı Bez 10x10 cm');
    await expect(row).toContainText('LOT E2E-LOT-1');
    await expect(row).toContainText('31.12.2030');
    expect(errors).toEqual([]);
  });

  test('tanımsız karekod anlaşılır uyarı verir', async ({ page }) => {
    await page.goto('/stok');
    await page.getByRole('button', { name: /Stok girişi/ }).click();
    await page.locator('#op-scan').fill('(01)09999999999999(10)X');
    await page.locator('#op-scan').press('Enter');
    await expect(page.getByText(/hiçbir ürüne tanımlı değil/)).toBeVisible();
  });

  test('SKT filtresi yalnızca süresi yaklaşan lotları gösterir', async ({ page }) => {
    await page.goto('/stok');
    // Filtrelenmiş liste gelmeden satırlar sayılmasın
    const filtered = page.waitForResponse((r) => r.url().includes('/stocks?') && r.url().includes('expiry=warning'));
    await page.getByRole('button', { name: /SKT yaklaşan/ }).click();
    await filtered;
    const rows = page.locator('tbody tr');
    await expect(rows.first()).toBeVisible();
    // Her satırda uyarı renginde SKT rozeti var
    for (const r of await rows.all()) {
      await expect(r.locator('app-expiry-badge .badge-warn, app-expiry-badge .badge-danger')).toHaveCount(1);
    }
  });

  test('hastaneye düz transfer seçeneği yok (yalnızca teslimat ekranından)', async ({ page }) => {
    await page.goto('/stok');
    await page.getByRole('button', { name: /^.*Transfer$/ }).click();
    const options = await page.locator('#op-to option').allTextContents();
    expect(options.some((o) => o.includes('konsinye'))).toBe(false);
  });
});
