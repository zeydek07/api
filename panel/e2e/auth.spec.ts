import { expect, test } from '@playwright/test';
import { USERS, login } from './helpers';

// Bu dosyadaki testler oturumsuz başlar
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Giriş ve oturum', () => {
  test('oturum açmadan korumalı sayfaya gidilemez, girişten sonra o sayfaya dönülür', async ({ page }) => {
    await page.goto('/urunler');
    await expect(page).toHaveURL(/\/giris\?r=%2Furunler/);

    await page.locator('#email').fill(USERS.admin.email);
    await page.locator('#password').fill(USERS.admin.password);
    await page.getByRole('button', { name: 'Giriş yap' }).click();

    await expect(page).toHaveURL(/\/urunler$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Ürünler' })).toBeVisible();
  });

  test('yanlış şifre anlaşılır hata verir', async ({ page }) => {
    await page.goto('/giris');
    await page.locator('#email').fill(USERS.admin.email);
    await page.locator('#password').fill('yanlis-sifre');
    await page.getByRole('button', { name: 'Giriş yap' }).click();

    await expect(page.getByRole('alert')).toContainText('E-posta veya şifre hatalı');
    await expect(page).toHaveURL(/\/giris/);
  });

  test('çıkış yapınca oturum kapanır', async ({ page }) => {
    await login(page, USERS.mumessil.email, USERS.mumessil.password);
    await page.getByRole('button', { name: 'Kullanıcı menüsü' }).click();
    await page.getByRole('menuitem', { name: /Çıkış yap/ }).click();
    await expect(page).toHaveURL(/\/giris/);

    // Token silindi: geri gelince yine girişe yönlenir
    await page.goto('/teslimat');
    await expect(page).toHaveURL(/\/giris/);
  });
});
