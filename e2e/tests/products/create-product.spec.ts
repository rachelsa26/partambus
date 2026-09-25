import { buildProduct, seededProducts } from '../../src/data/factory';
import { expect, test } from '../../src/fixtures/test';

test.describe('Tambah produk', { tag: '@products' }, () => {
  test.use({ loginAs: 'owner' });

  test.beforeEach(async ({ productFormPage }) => {
    await productFormPage.goto();
  });

  test('produk baru tersimpan, tercatat di audit log', { tag: '@smoke' }, async ({ page, productFormPage, db }) => {
    const product = buildProduct();

    await productFormPage.create(product);

    await test.step('tampilan: kembali ke daftar produk dengan pesan sukses', async () => {
      await expect(page).toHaveURL(/\/products\/index\.php$/);
      await expect(productFormPage.successFlash).toHaveText(`Produk "${product.name}" berhasil dibuat.`);
      await expect(page.getByRole('cell', { name: product.code })).toBeVisible();
    });

    await test.step('database: produk dan jejak audit tersimpan', async () => {
      expect(await db.productExists(product.code)).toBe(true);
      expect(await db.productStock(product.code)).toBe(0);
      const audit = await db.productCreatedAudit(product.code);
      expect(JSON.parse(audit?.after_value ?? '{}')).toMatchObject({ code: product.code, name: product.name });
    });
  });

  test('kode produk harus unik tanpa membedakan huruf besar/kecil', async ({ productFormPage }) => {
    const duplicate = buildProduct({ code: seededProducts.kopi.code.toLowerCase() });

    await productFormPage.create(duplicate);

    await expect(productFormPage.errorFlash).toContainText(`Kode produk "${duplicate.code}" sudah dipakai produk lain.`);
  });

  test('produk tanpa satuan yang bisa dijual ditolak', async ({ productFormPage, db }) => {
    const product = buildProduct();

    await productFormPage.create(product, { sellable: false });

    await expect(productFormPage.errorFlash).toContainText('Produk harus punya minimal satu unit yang bisa dijual');
    expect(await db.productExists(product.code)).toBe(false);
  });

  test('field wajib kosong menampilkan semua pesan validasi sekaligus', async ({ productFormPage }) => {
    await productFormPage.save.click();

    await expect(productFormPage.errorFlash).toContainText([
      'Satuan dasar: pilih satuan.',
      'Kode produk wajib diisi.',
      'Nama produk wajib diisi.',
    ]);
  });
});
