import { expect, test } from '@playwright/test';
import { USERS, collectErrors, menuLabels } from './helpers';

/** Her rolün görmesi gereken menü ve giremeyeceği sayfalar */
const CASES = [
  {
    role: 'admin' as const,
    menu: [
      'Kontrol Paneli', 'Hastaneye Teslim', 'Teslimatlar', 'Hastaneler', 'Ürünler', 'Stok Durumu',
      'Stok Hareketleri', 'Depolar', 'Raporlar', 'Personeller', 'Roller ve Yetkiler',
    ],
    forbidden: [] as string[],
  },
  {
    role: 'depocu' as const,
    menu: ['Kontrol Paneli', 'Teslimatlar', 'Hastaneler', 'Ürünler', 'Stok Durumu', 'Stok Hareketleri', 'Depolar', 'Raporlar'],
    forbidden: ['/personeller', '/roller', '/teslimat'],
  },
  {
    role: 'mumessil' as const,
    menu: ['Hastaneye Teslim', 'Teslimatlar', 'Hastaneler', 'Ürünler'],
    forbidden: ['/personeller', '/roller', '/stok', '/raporlar', '/depolar'],
  },
];

for (const c of CASES) {
  test.describe(`Yetkiler: ${c.role}`, () => {
    test.use({ storageState: USERS[c.role].state });

    test('menüde yalnızca yetkili sayfalar var ve hepsi hatasız açılıyor', async ({ page }) => {
      const errors = collectErrors(page);
      await page.goto('/');
      await expect(page.locator('aside nav a').first()).toBeVisible();
      expect(await menuLabels(page)).toEqual(c.menu);

      for (const href of await page.locator('aside nav a').evaluateAll((els) => els.map((e) => e.getAttribute('href')!))) {
        await page.goto(href);
        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page).toHaveURL(new RegExp(href + '$'));
      }
      expect(errors).toEqual([]);
    });

    for (const href of c.forbidden) {
      test(`${href} adresine doğrudan gidince "yetkiniz yok" görür`, async ({ page }) => {
        await page.goto(href);
        await expect(page).toHaveURL(/\/yetkisiz$/);
        await expect(page.getByRole('heading', { name: 'Bu sayfayı görme yetkiniz yok' })).toBeVisible();
      });
    }
  });
}

test.describe('Yetkiye göre düğmeler', () => {
  test.use({ storageState: USERS.mumessil.state });

  test('mümessil ürün ekleyemez, düzenleyemez', async ({ page }) => {
    await page.goto('/urunler');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /Yeni ürün/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'İşlemler' })).toHaveCount(0);
  });
});
