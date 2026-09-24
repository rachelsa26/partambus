import { env } from '../../src/config/env';
import { expect, test } from '../../src/fixtures/test';

test.describe('Login', { tag: '@auth' }, () => {
  test.beforeEach(async ({ loginPage }) => {
    await loginPage.goto();
  });

  test('owner berhasil login dan diarahkan ke dashboard', { tag: '@smoke' }, async ({ page, loginPage, dashboardPage }) => {
    await loginPage.login(env.users.owner);

    await expect(page).toHaveURL(/\/dashboard\.php$/);
    await expect(dashboardPage.heading).toHaveText('Dashboard');
    await dashboardPage.expectLoggedInAs(env.users.owner.fullName, 'owner');
  });

  test('kasir berhasil login dan diarahkan ke dashboard', { tag: '@smoke' }, async ({ page, loginPage, dashboardPage }) => {
    await loginPage.login(env.users.cashier);

    await expect(page).toHaveURL(/\/dashboard\.php$/);
    await dashboardPage.expectLoggedInAs(env.users.cashier.fullName, 'cashier');
  });

  const invalidCases = [
    { title: 'password salah', username: env.users.owner.username, password: 'PasswordSalah!' },
    { title: 'username tidak terdaftar', username: 'user_tidak_ada', password: 'Apa saja123' },
    { title: 'akun nonaktif walau password benar', ...env.users.inactive },
  ];

  for (const { title, username, password } of invalidCases) {
    test(`login ditolak: ${title}`, async ({ page, loginPage }) => {
      await loginPage.login({ username, password });

      await expect(page).toHaveURL(/\/auth\/login\.php$/);
      // Pesan sengaja generik supaya tidak membocorkan username mana yang valid.
      await expect(loginPage.error).toHaveText('Username atau password salah, atau akun tidak aktif.');
    });
  }

  test('field kosong menampilkan pesan wajib diisi', async ({ loginPage }) => {
    await loginPage.submit.click();

    await expect(loginPage.error).toHaveText('Username dan password wajib diisi.');
  });

  test('logout mengakhiri sesi', async ({ page, loginPage, dashboardPage }) => {
    await loginPage.login(env.users.owner);
    await expect(page).toHaveURL(/\/dashboard\.php$/);

    await dashboardPage.logout();
    await expect(page).toHaveURL(/\/auth\/login\.php$/);

    // Kembali ke halaman terproteksi harus dilempar lagi ke login.
    await page.goto('/dashboard.php');
    await expect(page).toHaveURL(/\/auth\/login\.php$/);
  });
});

test.describe('Halaman terproteksi tanpa login', { tag: '@auth' }, () => {
  const protectedPaths = ['/dashboard.php', '/pos/index.php', '/products/index.php', '/users/index.php', '/cash/index.php'];

  for (const path of protectedPaths) {
    test(`${path} mengarah ke halaman login`, async ({ page }) => {
      await page.goto(path);
      await expect(page).toHaveURL(/\/auth\/login\.php$/);
    });
  }
});
