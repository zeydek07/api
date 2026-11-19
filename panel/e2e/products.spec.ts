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
});
