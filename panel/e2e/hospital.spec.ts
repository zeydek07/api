import { expect, test } from '@playwright/test';
import { USERS, collectErrors, pickFromSearchSelect } from './helpers';

test.describe('Hastaneler (yönetici)', () => {
  test.use({ storageState: USERS.admin.state });

  test('il ve ilçe aranarak hastane eklenir; konsinye lokasyonu kendiliğinden açılır', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/hastaneler');
    await page.getByRole('button', { name: /Yeni hastane/ }).click();
    await page.locator('#h-name').fill('E2E Test Hastanesi');
    await page.locator('#h-type').selectOption({ label: 'Şehir hastanesi' });

    // İlçe, il seçilmeden kapalı
    await expect(page.locator('#h-district')).toBeDisabled();

    // Türkçe karakter yazmadan arama
    await pickFromSearchSelect(page, '#h-city', 'izmir', 'İzmir');
    await pickFromSearchSelect(page, '#h-district', 'bornova', 'Bornova');

    await page.getByRole('button', { name: 'Kaydet' }).click();
    await expect(page.getByText('Hastane kaydedildi.')).toBeVisible();

    await page.getByRole('textbox', { name: 'Ara' }).fill('E2E Test');
    const row = page.locator('tbody tr').first();
    await expect(row).toContainText('E2E Test Hastanesi');
    await expect(row).toContainText('İzmir');
    await expect(row).toContainText('Bornova');

    await row.getByRole('link', { name: 'E2E Test Hastanesi' }).click();
    await page.getByRole('tab', { name: 'Lokasyonlar' }).click();
    await expect(page.locator('app-hospital-locations')).toContainText('Ana konsinye deposu');
    expect(errors).toEqual([]);
  });

  test('ilçe başka bir ile ait olamaz: il değişince ilçe temizlenir', async ({ page }) => {
    await page.goto('/hastaneler');
    await page.getByRole('button', { name: /Yeni hastane/ }).click();
    await pickFromSearchSelect(page, '#h-city', 'istanbul', 'İstanbul');
    await pickFromSearchSelect(page, '#h-district', 'kartal', 'Kartal');

    await pickFromSearchSelect(page, '#h-city', 'ankara', 'Ankara');
    await expect(page.locator('#h-district')).toContainText('İlçe seçin');
    // Yeni ilin ilçeleri gelir
    await pickFromSearchSelect(page, '#h-district', 'mamak', 'Mamak');
  });

  test('hastane personeli eklenir ve listede görünür', async ({ page }) => {
    await page.goto('/hastaneler');
    await page.getByRole('link', { name: 'Kartal Devlet Hastanesi' }).click();
    await page.getByRole('tab', { name: 'Hastane personeli' }).click();
    await page.getByRole('button', { name: /Kişi ekle/ }).click();
    await page.locator('#hc-name').fill('E2E Hemşire');
    await page.locator('#hc-title').fill('Ameliyathane sorumlusu');
    await page.locator('#hc-phone').fill('0532 000 00 00');
    await page.locator('app-modal').getByRole('button', { name: 'Kaydet' }).click();
    await expect(page.locator('app-hospital-contacts')).toContainText('E2E Hemşire');
    await expect(page.locator('app-hospital-contacts')).toContainText('Ameliyathane sorumlusu');
  });
});
