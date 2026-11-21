import { expect, test } from '@playwright/test';
import { USERS, collectErrors, menuLabels } from './helpers';

test.describe('İK: saha personeli', () => {
  test.use({ storageState: USERS.mumessil.state });

  test('saha personeli Benim Sayfam görür, İK menüsünü görmez', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/');
    await expect(page.locator('aside nav a').first()).toBeVisible();
    await expect(page.locator('aside nav')).toContainText('Benim Sayfam');

    const labels = await menuLabels(page);
    expect(labels).toContain('Profilim');
    expect(labels).not.toContain('Kullanıcılar');
    expect(labels).not.toContain('Personeller');

    await page.locator('aside nav a', { hasText: 'Profilim' }).click();
    await expect(page).toHaveURL(/\/benim$/);
    await expect(page.getByRole('heading', { name: 'Benim Sayfam' })).toBeVisible();
    await expect(page.getByText('Personel kartınız henüz oluşturulmadı')).toBeVisible();
    await expect(page.getByText('İnsan Kaynakları ile iletişime geçin')).toBeVisible();
    expect(errors).toEqual([]);
  });
});

test.describe('İK: yönetim', () => {
  test.use({ storageState: USERS.admin.state });

  test('yönetim menüsünde Kullanıcılar yazar', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('aside nav a').first()).toBeVisible();
    const labels = await menuLabels(page);
    expect(labels).toContain('Kullanıcılar');
    expect(labels).not.toContain('Personeller');

    await page.locator('aside nav a', { hasText: 'Kullanıcılar' }).click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole('heading', { name: 'Kullanıcılar' })).toBeVisible();
  });
});
