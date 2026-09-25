import { env } from '../../src/config/env';
import { expect, test } from '../../src/fixtures/test';

test.describe('Keamanan dasar', { tag: '@security' }, () => {
  // Login owner hanya terjadi di test yang memakai `page`; test yang memakai
  // fixture `request` berjalan sebagai pengunjung tanpa login.
  test.use({ loginAs: 'owner' });

  test('form tanpa CSRF token ditolak dengan 403', async ({ page, db }) => {
    const supplierName = `Supplier tanpa CSRF ${Date.now()}`;

    const response = await page.request.post('/suppliers/create.php', {
      form: { name: supplierName },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(403);
    expect(await response.text()).toContain('CSRF check gagal');
    // Tidak boleh ada data yang tersimpan.
    expect(await db.supplierExists(supplierName)).toBe(false);
  });

  test('login tanpa CSRF token ditolak', async ({ request }) => {
    const response = await request.post('/auth/login.php', {
      form: { username: env.users.owner.username, password: env.users.owner.password },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(403);
  });

  /**
   * Folder & file ini dilindungi .htaccess. Test ini hanya bermakna
   * jika aplikasi berjalan di Apache (seperti di Docker/hosting),
   * bukan `php -S`.
   */
  const privatePaths = [
    '/database/schema.sql',
    '/config/database.php',
    '/config/env.local.php.example',
    '/lib/auth.php',
    '/composer.json',
    '/backups/',
  ];

  for (const path of privatePaths) {
    test(`file internal tidak bisa diakses publik: ${path}`, async ({ request }) => {
      const response = await request.get(path);
      expect(response.status()).toBe(403);
    });
  }

  test(
    'BUG: metadata dependency di /vendor terbuka untuk publik',
    {
      tag: '@known-bug',
      annotation: {
        type: 'issue',
        description:
          'vendor/composer/installed.json membocorkan versi library. Tambahkan .htaccess "Require all denied" di vendor/.',
      },
    },
    async ({ request }) => {
      test.fail(); // Hapus baris ini setelah bug diperbaiki; test akan jadi penjaga regresi.
      const response = await request.get('/vendor/composer/installed.json');
      expect(response.status()).toBe(403);
    },
  );

  test(
    'BUG: open redirect lewat parameter return_to di form supplier',
    {
      tag: '@known-bug',
      annotation: {
        type: 'issue',
        description:
          'suppliers/create.php meneruskan return_to ke header Location tanpa validasi. "//evil.example" mengarahkan user ke domain lain.',
      },
    },
    async ({ page, api }) => {
      test.fail(); // Hapus baris ini setelah bug diperbaiki.
      await page.goto('/suppliers/create.php');

      const response = await api.submitForm('/suppliers/create.php', {
        name: `Supplier redirect ${Date.now()}`,
        return_to: '//evil.example/phish',
      });

      expect(response.status()).toBe(302);
      expect(response.headers()['location']).not.toMatch(/^\/\//);
    },
  );
});
