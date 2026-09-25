import { expect, test } from '../../src/fixtures/test';

test.describe('Dashboard', { tag: '@dashboard' }, () => {
  test.use({ loginAs: 'owner' });

  // Regresi temuan #8: saat omzet 0, label sumbu Y dulu berulang (Rp1, Rp1, Rp1, Rp0, Rp0).
  test('label sumbu Y setiap grafik tren tidak ada yang berulang', async ({ dashboardPage }) => {
    await dashboardPage.goto();
    await expect(dashboardPage.trendCharts.first()).toBeAttached();

    for (const chart of await dashboardPage.trendCharts.all()) {
      const name = await chart.getAttribute('aria-label');
      const labels = await dashboardPage.yAxisLabels(chart).allTextContents();
      expect(labels.length, `${name}: jumlah label`).toBeGreaterThanOrEqual(2);
      expect(new Set(labels).size, `${name}: ${labels.join(', ')}`).toBe(labels.length);
    }
  });
});
