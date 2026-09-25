import { seededProducts } from '../../src/data/factory';
import { expect, test } from '../../src/fixtures/test';

/** Regresi untuk temuan aksesibilitas #6 dan #7. */
test.describe('Aksesibilitas', { tag: '@a11y' }, () => {
  test.describe('sebagai owner', () => {
    test.use({ loginAs: 'owner' });

    test('dropdown satuan dasar punya label yang terhubung', async ({ page, productFormPage }) => {
      await productFormPage.goto();

      const baseUnit = page.getByLabel('Satuan Dasar (base unit)');
      await expect(baseUnit).toBeVisible();
      await expect(baseUnit).toHaveAttribute('name', 'base_unit_name');
    });
  });

  test.describe('sebagai kasir', () => {
    test.use({ loginAs: 'cashier' });

    test('hasil pencarian POS bisa dipilih dengan keyboard', async ({ posPage }) => {
      const { kopi } = seededProducts;
      await posPage.goto();

      await posPage.addFromSearch(kopi, { useKeyboard: true });

      await expect(posPage.cartLine(kopi.name)).toBeVisible();
    });
  });
});
