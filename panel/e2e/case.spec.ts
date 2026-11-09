import { Page, expect, test } from '@playwright/test';
import { USERS, apiGet, collectErrors, pickFromSearchSelect } from './helpers';

const HOSPITAL = 'Kartal Devlet Hastanesi';
const SIGNATURE =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

interface StockRow {
  id: number;
  quantity: string;
  expiry_date: string | null;
  product: { name: string };
}

/** Oturumdaki token ile API'ye yazar (mobil uygulamanın yaptığı "ameliyatta ürün kaydı" adımı panelde yok) */
async function apiPost<T>(page: Page, path: string, body: unknown): Promise<T> {
  return page.evaluate(
    async ([p, b]) => {
      const token = localStorage.getItem('medikal.token');
      const r = await fetch('/api' + p, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(b),
      });
      if (!r.ok) throw new Error(`${r.status} ${p} ${await r.text()}`);
      return r.json();
    },
    [path, body] as const,
  );
}

test.describe.serial('Vaka döngüsü: planla, kaydet, onayla, faturala (yönetici)', () => {
  test.use({ storageState: USERS.admin.state });

  let caseId = 0;
  let caseNumber = '';
  let productName = '';
  const invoiceNo = `E2E-${Date.now()}`;

  test('vaka planlar; yeni doktor ekler; detay sayfası "mobilde kaydedilir" der', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/vakalar');
    await expect(page.getByRole('heading', { name: 'Vakalar', level: 1 })).toBeVisible();

    await page.getByRole('button', { name: /Vaka planla/ }).click();
    const dialog = page.locator('app-modal');
    await pickFromSearchSelect(page, '#pc-hospital', 'Kartal', HOSPITAL);

    // Hastanenin doktor listesi gelince yeni doktor eklenir ve seçilir
    await dialog.getByRole('button', { name: '+ Yeni doktor' }).click();
    await dialog.locator('#nd-name').fill('E2E Cerrah');
    await dialog.locator('#nd-title').fill('Prof. Dr.');
    await dialog.locator('#nd-branch').fill('Ortopedi');
    await dialog.getByRole('button', { name: 'Doktoru ekle' }).click();
    await expect(dialog.locator('#pc-doctor option:checked')).toContainText('E2E Cerrah');

    // Aynı doktor ikinci kez eklenemez; hata alanın yanında görünür
    await dialog.getByRole('button', { name: '+ Yeni doktor' }).click();
    await dialog.locator('#nd-name').fill('E2E Cerrah');
    await dialog.getByRole('button', { name: 'Doktoru ekle' }).click();
    await expect(dialog.getByRole('alert')).toContainText('zaten kayıtlı');
    await dialog.getByRole('button', { name: 'Vazgeç' }).first().click();

    await dialog.locator('#pc-procedure').fill('E2E Total diz protezi');
    await dialog.locator('#pc-protocol').fill('E2E-PRT-1');
    await dialog.locator('#pc-note').fill('E2E planı');
    await dialog.getByRole('button', { name: 'Planla' }).click();

    await expect(page).toHaveURL(/\/vakalar\/\d+$/);
    caseId = Number(page.url().split('/').pop());
    caseNumber = (await page.locator('h1').first().textContent())!.trim();
    expect(caseNumber).toMatch(/^VK-\d{4}-\d{6}$/);

    const article = page.locator('article');
    await expect(article.getByText('Planlandı', { exact: true })).toBeVisible();
    await expect(article).toContainText(HOSPITAL);
    await expect(article).toContainText('Prof. Dr. E2E Cerrah');
    await expect(article).toContainText('E2E Total diz protezi');
    await expect(article).toContainText('Ürünler mobil uygulamada ameliyat sırasında kaydedilir');
    // Planlanmış vakada onay ve PDF yok; plan düzenlenebilir, iptal edilebilir
    await expect(page.getByRole('button', { name: 'Onayla' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /PDF indir/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Planı düzenle/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /İptal et/ })).toBeVisible();

    // Liste: yeni vaka "Planlandı" sekmesinde ve aramayla bulunur
    await page.goto('/vakalar');
    await page.getByRole('tab', { name: 'Planlandı' }).click();
    await page.getByRole('textbox', { name: 'Ara' }).fill(caseNumber);
    await expect(page.locator('tbody tr', { hasText: caseNumber })).toContainText('E2E Total diz protezi');

    expect(errors).toEqual([]);
  });

  test('planı düzenler', async ({ page }) => {
    await page.goto(`/vakalar/${caseId}`);
    await page.getByRole('button', { name: /Planı düzenle/ }).click();
    await page.locator('#pc-protocol').fill('E2E-PRT-2');
    const saved = page.waitForResponse((r) => r.url().includes(`/api/cases/${caseId}`) && r.request().method() === 'PUT');
    await page.locator('app-modal').getByRole('button', { name: 'Kaydet' }).click();
    expect((await saved).ok()).toBe(true);
    await expect(page.locator('article')).toContainText('E2E-PRT-2');
  });

  test('ameliyatta kaydedilen vakayı onaylar', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto(`/vakalar/${caseId}`);

    // Mobil uygulamanın yaptığı adım: hastane rafındaki bir ürün "kullanıldı" olarak kaydedilir
    const kase = await apiGet<{ hospital: { id: number } }>(page, `/cases/${caseId}`);
    const consignment = await apiGet<{ rows: StockRow[] }>(page, `/hospitals/${kase.hospital.id}/consignment`);
    const today = new Date().toISOString().slice(0, 10);
    const usable = (x: StockRow) => Number(x.quantity) >= 1 && (!x.expiry_date || x.expiry_date >= today);
    let row = consignment.rows.find(usable);
    if (!row) {
      // Örnek veride bu hastanenin rafı boş olabilir: yönetici (süper yönetici) herhangi bir iç depodan düşebilir
      const all = await apiGet<{ data: (StockRow & { warehouse: { type: string } })[] }>(page, '/stock?per_page=100');
      row = all.data.find((x) => x.warehouse.type !== 'consignment' && usable(x));
    }
    expect(row, 'kaydedilecek ürün bulunmalı').toBeTruthy();
    row = row!;
    productName = row.product.name;
    await apiPost(page, `/cases/${caseId}/record`, {
      signer_name: 'E2E Hemşire',
      signature: SIGNATURE,
      latitude: 40.9,
      longitude: 29.18,
      location_accuracy: 12,
      items: [{ stock_id: row.id, quantity: 1, status: 'used' }],
    });

    await page.reload();
    const article = page.locator('article');
    await expect(article.getByText('Onay bekliyor', { exact: true })).toBeVisible();
    await expect(article).toContainText(productName);
    await expect(article).toContainText(/Hastane:|Araç:/);
    await expect(article).toContainText('E2E Hemşire');
    await expect(page.getByAltText('Tutanağı imzalayanın imzası')).toBeVisible();
    // Kaydedilmiş vakada plan düzenlenemez
    await expect(page.getByRole('button', { name: /Planı düzenle/ })).toHaveCount(0);

    // Sunucudan PDF
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: /PDF indir/ }).click();
    expect((await download).suggestedFilename()).toBe(`${caseNumber}.pdf`);

    // Onay
    await page.getByRole('button', { name: 'Onayla' }).click();
    const approved = page.waitForResponse((r) => r.url().endsWith(`/api/cases/${caseId}/approve`));
    await page.locator('.modal-panel').getByRole('button', { name: 'Onayla' }).click();
    expect((await approved).ok()).toBe(true);
    await expect(article.getByText('Onaylandı', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Onayla' })).toHaveCount(0);

    expect(errors).toEqual([]);
  });

  test('faturalama: satırı seçip faturalar, Faturalananlar sekmesine geçer, işareti geri alır', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/faturalama');
    await expect(page.getByRole('heading', { name: 'Faturalama', level: 1 })).toBeVisible();

    const pendingRow = page.locator('tbody tr', { hasText: caseNumber });
    await expect(pendingRow).toContainText(productName);
    await expect(pendingRow).toContainText('Kullanıldı');
    await pendingRow.getByRole('checkbox').check();
    await expect(page.getByText('1 satır seçildi')).toBeVisible();

    await page.getByRole('button', { name: 'Faturalandı işaretle' }).click();
    const dialog = page.locator('app-modal');
    // Gelecek tarih seçilemez
    await expect(dialog.locator('#bi-date')).toHaveAttribute('max', /^\d{4}-\d{2}-\d{2}$/);
    await expect(dialog.getByRole('button', { name: 'Faturalandı işaretle' })).toBeDisabled();
    await dialog.locator('#bi-no').fill(invoiceNo);
    const invoiced = page.waitForResponse((r) => r.url().endsWith('/api/billing/invoice'));
    await dialog.getByRole('button', { name: 'Faturalandı işaretle' }).click();
    expect((await invoiced).ok()).toBe(true);

    // Faturalanacaklardan düştü
    await expect(page.locator('tbody tr', { hasText: caseNumber })).toHaveCount(0);

    // Faturalananlarda fatura grubunun altında
    await page.getByRole('tab', { name: 'Faturalananlar' }).click();
    const group = page.getByRole('region', { name: `Fatura ${invoiceNo}` });
    await expect(group).toBeVisible();
    await expect(group).toContainText(caseNumber);
    await expect(group).toContainText(productName);

    // Vaka kartında fatura no görünür
    await page.goto(`/vakalar/${caseId}`);
    await expect(page.locator('article')).toContainText(invoiceNo);
    // Faturalanmış vaka iptal edilemez
    await expect(page.getByRole('button', { name: /İptal et/ })).toHaveCount(0);

    // İşareti geri al
    await page.goto('/faturalama');
    await page.getByRole('tab', { name: 'Faturalananlar' }).click();
    await page.getByRole('region', { name: `Fatura ${invoiceNo}` }).getByRole('button', { name: /İşareti geri al/ }).click();
    const undone = page.waitForResponse((r) => r.url().endsWith('/api/billing/uninvoice'));
    await page.locator('.modal-panel').getByRole('button', { name: 'İşareti geri al' }).click();
    expect((await undone).ok()).toBe(true);
    await expect(page.getByRole('region', { name: `Fatura ${invoiceNo}` })).toHaveCount(0);

    await page.getByRole('tab', { name: 'Faturalanacaklar' }).click();
    await expect(page.locator('tbody tr', { hasText: caseNumber })).toBeVisible();
    expect(errors).toEqual([]);
  });

  test('hastane kartında Doktorlar ve Vakalar sekmeleri', async ({ page }) => {
    await page.goto('/hastaneler');
    await page.getByRole('link', { name: new RegExp(HOSPITAL) }).first().click();

    await page.getByRole('tab', { name: /Doktorlar/ }).click();
    const doctorRow = page.locator('tbody tr', { hasText: 'E2E Cerrah' });
    await expect(doctorRow).toContainText('Ortopedi');
    await expect(doctorRow).toContainText('1'); // vaka sayısı

    await page.getByRole('tab', { name: /Vakalar/ }).click();
    await expect(page.locator('tbody tr', { hasText: caseNumber })).toBeVisible();
  });
});
