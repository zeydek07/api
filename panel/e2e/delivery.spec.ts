import { expect, test } from '@playwright/test';
import { USERS, apiGet, collectErrors, sign } from './helpers';

interface StockRow {
  id: number;
  lot_number: string | null;
  expiry_date: string | null;
  quantity: string;
  product: { name: string; gtin: string | null };
}

const HOSPITAL = 'Kartal Devlet Hastanesi';

test.describe.serial('Konsinye teslimat (mümessil)', () => {
  test.use({ storageState: USERS.mumessil.state });

  let deliveryNumber = '';

  test('imza olmadan teslimat tamamlanamaz', async ({ page }) => {
    await page.goto('/teslimat');
    await page.getByRole('button', { name: new RegExp(HOSPITAL) }).click();
    await page.locator('button.chip', { hasText: 'Sorumlu hemşire' }).first().click();
    await page.locator('section li:has(input[type=number])').first().getByRole('button', { name: /artır/ }).click();

    await expect(page.getByRole('button', { name: /Teslimatı tamamla/ })).toBeDisabled();
    await expect(page.getByText('Teslim alanın imzası gerekli')).toBeVisible();
  });

  test('karekod okutup imzalı teslimat yapar; stok araçtan hastaneye geçer', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/teslimat');

    // 1. Hastane: sorumlu olunanlar önce ve yıldızlı
    await expect(page.locator('section').first().locator('.icon', { hasText: 'star' }).first()).toBeVisible();
    await page.getByRole('button', { name: new RegExp(HOSPITAL) }).click();

    // 2. Tek lokasyon ve tek araç kendiliğinden seçilir
    await expect(page.locator('.chip-on', { hasText: 'Ana konsinye deposu' })).toBeVisible();
    await expect(page.locator('.chip-on', { hasText: 'Araç' })).toBeVisible();

    // 3. Teslim alan: hastane personelinden
    await page.locator('button.chip', { hasText: 'Sorumlu hemşire' }).first().click();

    // 4. Araçtan SKT'si geçmemiş, en az 2 adetlik bir lotu karekodla okut
    const vehicles = await apiGet<{ id: number }[]>(page, '/mobile/warehouses');
    const stock = await apiGet<{ stocks: StockRow[] }>(page, `/mobile/warehouses/${vehicles[0].id}/stock`);
    const today = new Date().toISOString().slice(0, 10);
    const row = stock.stocks.find((s) => Number(s.quantity) >= 2 && s.product.gtin && s.lot_number && (!s.expiry_date || s.expiry_date >= today))!;
    expect(row, 'araçta okutulacak uygun lot bulunmalı').toBeTruthy();

    const scan = page.getByRole('textbox', { name: 'Karekod okut' });
    await scan.fill(`(01)${row.product.gtin}(10)${row.lot_number}`);
    await scan.press('Enter');
    await expect(page.getByText(`✓ ${row.product.name}`)).toBeVisible();

    // 5. İmza
    await sign(page);
    await page.locator('#d-note').fill('E2E: karekodla teslimat');

    const submit = page.getByRole('button', { name: /Teslimatı tamamla/ });
    await expect(submit).toBeEnabled();
    await submit.click();

    await expect(page.getByRole('heading', { name: 'Teslimat kaydedildi' })).toBeVisible();
    deliveryNumber = (await page.locator('p.font-mono').first().textContent())!.trim();
    expect(deliveryNumber).toMatch(/^TS-\d{4}-\d{6}$/);

    // Stok araçtan 1 düştü
    const after = await apiGet<{ stocks: StockRow[] }>(page, `/mobile/warehouses/${vehicles[0].id}/stock`);
    expect(Number(after.stocks.find((s) => s.id === row.id)?.quantity)).toBe(Number(row.quantity) - 1);

    // Belge: ürün, lot ve imza görünür
    await page.getByRole('link', { name: /Belgeyi görüntüle/ }).click();
    await expect(page.locator('article')).toContainText(deliveryNumber);
    await expect(page.locator('article')).toContainText(row.product.name);
    await expect(page.locator('article')).toContainText(`LOT ${row.lot_number}`);
    await expect(page.getByAltText('Teslim alanın imzası')).toBeVisible();
    await expect(page.locator('article')).toContainText('mülkiyet firmada kalır');
    // İşlem anındaki konum belgede
    await expect(page.getByRole('link', { name: /İşlem konumunu haritada aç/ })).toHaveAttribute('href', /40\.9067.*29\.1932/);
    await expect(page.locator('article')).toContainText('Konum doğruluğu ±15 m');
    // Mümessil iptal edemez
    await expect(page.getByRole('button', { name: /İptal et/ })).toHaveCount(0);

    expect(errors).toEqual([]);
  });

  test('hastaneden geri alma yapar (imza isteğe bağlı)', async ({ page }) => {
    await page.goto('/teslimat');
    await page.getByRole('tab', { name: /Hastaneden geri al/ }).click();
    await page.getByRole('button', { name: new RegExp(HOSPITAL) }).click();
    await expect(page.locator('section li:has(input[type=number])').first()).toBeVisible();

    await page.locator('section li:has(input[type=number])').first().getByRole('button', { name: /artır/ }).click();
    await page.locator('button.chip', { hasText: 'Depo görevlisi' }).first().click();
    await page.getByRole('button', { name: /Geri almayı tamamla/ }).click();

    await expect(page.getByRole('heading', { name: 'Geri alma kaydedildi' })).toBeVisible();
    await expect(page.locator('p.font-mono').first()).toHaveText(/^GA-\d{4}-\d{6}$/);
  });
});

test.describe('Teslimat iptali (yönetici)', () => {
  test.use({ storageState: USERS.admin.state });

  test('yönetici geri alma belgesini gerekçeyle iptal eder', async ({ page }) => {
    await page.goto('/teslimatlar');
    await page.getByRole('combobox', { name: 'Tür' }).selectOption('return');
    const link = page.locator('tbody a.font-mono').first();
    await expect(link).toHaveText(/^GA-/);
    await link.click();

    await page.getByRole('button', { name: /İptal et/ }).click();
    await page.locator('#cancel-reason').fill('E2E: yanlışlıkla geri alındı');
    await page.locator('app-modal').getByRole('button', { name: 'İptal et' }).click();

    await expect(page.locator('article').getByText('İptal edildi')).toBeVisible();
    await expect(page.locator('article')).toContainText('E2E: yanlışlıkla geri alındı');
    await expect(page.getByRole('button', { name: /İptal et/ })).toHaveCount(0);
  });
});

test.describe('Konum izni verilmeyen cihaz (mümessil)', () => {
  test.use({ storageState: USERS.mumessil.state, permissions: [] });

  test('işlem yine kaydedilir ama "konum alınamadı" olarak işaretlenir ve ayrıca listelenir', async ({ page }) => {
    await page.goto('/teslimat');
    await page.getByRole('tab', { name: /Hastaneden geri al/ }).click();
    await page.getByRole('button', { name: new RegExp(HOSPITAL) }).click();
    await expect(page.getByText(/Konum izni verilmedi\. Belge .konum alınamadı. olarak işaretlenir/)).toBeVisible();

    await page.locator('section li:has(input[type=number])').first().getByRole('button', { name: /artır/ }).click();
    await page.locator('button.chip', { hasText: 'Depo görevlisi' }).first().click();
    await page.getByRole('button', { name: /Geri almayı tamamla/ }).click();
    await expect(page.getByRole('heading', { name: 'Geri alma kaydedildi' })).toBeVisible();
    const number = (await page.locator('p.font-mono').first().textContent())!.trim();

    await page.getByRole('link', { name: /Belgeyi görüntüle/ }).click();
    await expect(page.locator('article')).toContainText('Konum alınamadı · Konum izni verilmedi');
    await expect(page.getByRole('link', { name: /haritada aç/ })).toHaveCount(0);

    // Konumsuz belgeler filtresinde çıkar, konumlu filtresinde çıkmaz
    await page.goto('/teslimatlar');
    await page.getByRole('combobox', { name: 'Konum' }).selectOption('missing');
    const row = page.locator('tbody tr', { hasText: number });
    await expect(row).toBeVisible();
    await expect(row.getByText('Konumsuz')).toBeVisible();
    await page.getByRole('combobox', { name: 'Konum' }).selectOption('ok');
    await expect(page.locator('tbody tr', { hasText: number })).toHaveCount(0);
  });
});
