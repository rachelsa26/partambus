import { expect, test } from '../../src/fixtures/test';

/**
 * Role-based access control: kasir hanya boleh ke menu operasional,
 * semua menu manajemen khusus owner (lihat includes/sidebar.php).
 */
const ownerOnlyMenus = [
  { label: 'Inventaris', path: '/inventory/index.php' },
  { label: 'Supplier', path: '/suppliers/index.php' },
  { label: 'Pembelian', path: '/purchases/index.php' },
  { label: 'Laporan', path: '/reports/index.php' },
  { label: 'Pengguna', path: '/users/index.php' },
  { label: 'Pengaturan', path: '/settings/index.php' },
  { label: 'Log Audit', path: '/audit/index.php' },
  { label: 'Backup & Restore', path: '/backup/index.php' },
];

const sharedMenus = ['Dashboard', 'Kasir (POS)', 'Riwayat Penjualan', 'Cash Session', 'Produk'];

test.describe('Hak akses berdasarkan role', { tag: '@rbac' }, () => {
  test.describe('owner', () => {
    test.use({ loginAs: 'owner' });

    test('owner melihat semua menu', async ({ dashboardPage }) => {
      await dashboardPage.goto();

      for (const label of [...sharedMenus, ...ownerOnlyMenus.map((m) => m.label)]) {
        await expect(dashboardPage.sidebarLink(label), `menu ${label}`).toBeVisible();
      }
    });
  });

  test.describe('kasir', () => {
    test.use({ loginAs: 'cashier' });

    test('kasir tidak melihat menu khusus owner', async ({ dashboardPage }) => {
      await dashboardPage.goto();

      for (const label of sharedMenus) {
        await expect(dashboardPage.sidebarLink(label), `menu ${label}`).toBeVisible();
      }
      for (const { label } of ownerOnlyMenus) {
        await expect(dashboardPage.sidebarLink(label), `menu ${label}`).toHaveCount(0);
      }
    });

    // Menyembunyikan menu saja tidak cukup: URL-nya juga harus ditolak server.
    for (const { label, path } of ownerOnlyMenus) {
      test(`kasir mendapat 403 saat membuka ${label} langsung lewat URL`, async ({ page }) => {
        const response = await page.goto(path);

        expect(response?.status()).toBe(403);
        await expect(page.locator('body')).toContainText('Akses ditolak');
      });
    }
  });
});
