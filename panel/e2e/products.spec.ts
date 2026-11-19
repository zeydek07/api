import { expect, test } from '@playwright/test';
import { USERS, collectErrors } from './helpers';

test.describe('Ürün kartı (yönetici)', () => {
  test.use({ storageState: USERS.admin.state });

  test('GTIN kontrol hanesi formda denetlenir; karekoddan ürün numarası ayrılır', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/urunler');
    await page.getByRole('button', { name: /Yeni ürün/ }).click();
    await page.locator('#p-name').fill('E2E Vida');
    await page.locator('#p-code').fill('E2E-GTIN-1');

    const gtin = page.locator('#p-gtin');
    const save = page.getByRole('button', { name: 'Kaydet' });

    // Son hane yanlış (doğrusu 0)
    await gtin.fill('8691234567893');
    await gtin.blur();
    await expect(page.getByText('GTIN kontrol hanesi tutmuyor')).toBeVisible();
    await expect(save).toBeDisabled();

    // Karekod okutulunca yalnızca ürün numarası kalır, uyarı kalkar
    await gtin.fill('(01)08691234567890(17)271231(10)LOT42');
    await gtin.press('Enter');
    await expect(gtin).toHaveValue('08691234567890');
    await expect(page.getByText('GTIN kontrol hanesi tutmuyor')).toHaveCount(0);

    await save.click();
    await expect(page.getByText('Ürün kaydedildi.')).toBeVisible();
    expect(errors).toEqual([]);
  });

  test('HIBC ürün ve lot barkodu ayrı okutulunca stok girişinde tek satırda birleşir', async ({ page }) => {
    const errors = collectErrors(page);
    // Barkod alanına HIBC ürün kimliği yazılır (GTIN yok)
    await page.goto('/urunler');
    await page.getByRole('button', { name: /Yeni ürün/ }).click();
    await page.locator('#p-name').fill('E2E HIBC Plak');
    await page.locator('#p-code').fill('E2E-HIBC-1');
    await page.locator('#p-barcode').fill('+H123ABC01');
    await page.locator('#p-tracking').selectOption('lot');
    await page.getByRole('button', { name: 'Kaydet' }).click();
    await expect(page.getByText('Ürün kaydedildi.')).toBeVisible();

    await page.goto('/stok');
    await page.getByRole('button', { name: /Stok girişi/ }).click();
    await page.locator('#op-wh').selectOption({ label: 'Merkez Depo' });
    const scan = page.locator('#op-scan');

    // Ürün barkodu: lot takipli olduğu için lot barkodu beklenir
    await scan.fill('+H123ABC01C');
    await scan.press('Enter');
    await expect(page.getByText(/E2E HIBC Plak okundu\. Şimdi aynı etiketteki lot\/SKT barkodunu okutun/)).toBeVisible();

    // Başka etiketin lot barkodu (bağ karakteri X) kabul edilmez
    await scan.fill('+$$3271231LOT42X-');
    await scan.press('Enter');
    await expect(page.getByText(/okutulan ürüne ait değil/)).toBeVisible();

    // Aynı etiketin lot barkodu: tek satır, lot ve SKT dolu
    await scan.fill('+$$3271231LOT42CF');
    await scan.press('Enter');
    await expect(page.getByText('✓ E2E HIBC Plak · LOT LOT42 · SKT 2027-12-31')).toBeVisible();
    expect(errors).toEqual([]);
  });
});
