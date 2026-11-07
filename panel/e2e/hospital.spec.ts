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
  test('lokasyon ağacı: bölüm › oda › dolap › raf, teslimatta tam yol görünür', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/hastaneler');
    await page.getByRole('link', { name: 'Bakırköy Eğitim ve Araştırma Hastanesi' }).click();
    await page.getByRole('tab', { name: 'Lokasyonlar' }).click();
    const tab = page.locator('app-hospital-locations');

    // En üst seviyeye bölüm
    await tab.getByRole('combobox', { name: 'Yeni lokasyon seviyesi' }).selectOption({ label: 'Bölüm' });
    await tab.getByRole('textbox', { name: 'Yeni lokasyon adı' }).fill('E2E Ortopedi');
    await tab.getByRole('button', { name: /Lokasyon ekle/ }).click();
    await expect(page.getByText('Lokasyon eklendi.')).toBeVisible();

    // Bölümün altına oda, odanın altına dolap, dolabın altına raf
    const addUnder = async (parent: string, level: string, name: string) => {
      await tab.locator('li', { hasText: parent }).last().getByRole('button', { name: /Alt lokasyon/ }).click();
      await tab.getByRole('combobox', { name: 'Seviye', exact: true }).selectOption({ label: level });
      await tab.getByRole('textbox', { name: `${parent} altına yeni lokasyon adı` }).fill(name);
      await tab.getByRole('button', { name: 'Ekle', exact: true }).click();
      await expect(tab).toContainText(name);
    };
    await addUnder('E2E Ortopedi', 'Oda', 'E2E Ameliyathane');
    await addUnder('E2E Ameliyathane', 'Dolap', 'E2E Dolap A');
    await addUnder('E2E Dolap A', 'Raf', 'E2E Raf 1');

    // Raf en alt seviye: altına lokasyon eklenemez
    await expect(tab.locator('li', { hasText: 'E2E Raf 1' }).getByRole('button', { name: /Alt lokasyon/ })).toHaveCount(0);

    // Teslimat ekranında raf tam yoluyla seçilebilir
    await page.goto('/teslimat');
    await page.getByRole('textbox', { name: 'Hastane ara' }).fill('Bakırköy');
    await page.getByRole('button', { name: /Bakırköy Eğitim/ }).first().click();
    await expect(page.getByRole('button', { name: /E2E Ortopedi › E2E Ameliyathane › E2E Dolap A › E2E Raf 1/ })).toBeVisible();
    expect(errors).toEqual([]);
  });
});
