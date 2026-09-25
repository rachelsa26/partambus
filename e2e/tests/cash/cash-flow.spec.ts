import { decimal, rupiah, seededProducts } from '../../src/data/factory';
import { expect, test } from '../../src/fixtures/test';

/**
 * Aplikasi hanya mengizinkan SATU sesi kas terbuka untuk seluruh toko.
 * Karena itu skenario kas dijalankan berurutan (serial) dari kondisi
 * awal yang dipaksa bersih: tidak ada sesi terbuka.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Sesi kas & pembayaran tunai', { tag: '@cash' }, () => {
  test.use({ loginAs: 'cashier' });

  const openingCash = 100_000;
  const { kopi } = seededProducts;
  const qty = 3;
  const saleTotal = kopi.price * qty; // Rp6.000
  const cashReceived = 10_000;
  const expectedCash = openingCash + saleTotal;
  const shortage = 1_000;

  test.beforeAll(async ({ db }) => {
    await db.closeAllCashSessions();
  });

  test('tunai tidak bisa dipilih sebelum sesi kas dibuka', async ({ posPage }) => {
    await posPage.goto();

    await expect(posPage.noCashSessionWarning).toBeVisible();
    await expect(posPage.paymentMethod('Tunai')).toBeDisabled();
  });

  test('kasir membuka sesi kas', async ({ cashSessionPage, db }) => {
    await cashSessionPage.open(openingCash);

    await expect(cashSessionPage.successFlash).toHaveText(`Sesi kas dibuka dengan kas awal ${rupiah(openingCash)}.`);
    const session = await db.openCashSession();
    expect(Number(session?.opening_cash)).toBe(openingCash);
  });

  test.describe('sebagai owner', () => {
    test.use({ loginAs: 'owner' });

    test('sesi kas kedua tidak bisa dibuka selama masih ada yang aktif', async ({ page, cashSessionPage }) => {
      await cashSessionPage.gotoOpenForm();

      await expect(page).toHaveURL(/\/cash\/index\.php$/);
      await expect(cashSessionPage.errorFlash).toHaveText('Sudah ada sesi kas aktif.');
    });
  });

  test('pembayaran tunai menghitung kembalian dengan benar', { tag: '@smoke' }, async ({ posPage, db }) => {
    await test.step(`tambah ${qty} kopi ke keranjang`, async () => {
      await posPage.goto();
      await posPage.addFromSearch(kopi);
      await posPage.setQuantity(kopi.name, qty);
    });

    const { saleNumber } = await test.step(`bayar tunai ${rupiah(cashReceived)}`, async () => {
      await posPage.payWith('Tunai', cashReceived);
      return posPage.expectSaleCompleted();
    });

    await test.step('kembalian benar di layar dan di database', async () => {
      await expect(posPage.successChange).toHaveText(rupiah(cashReceived - saleTotal));
      expect(await db.payment(saleNumber)).toMatchObject({
        method: 'cash',
        amount: decimal(saleTotal),
        cash_received: decimal(cashReceived),
        change_amount: decimal(cashReceived - saleTotal),
      });
    });
  });

  test('uang diterima kurang dari total ditolak', async ({ posPage }) => {
    await posPage.goto();
    await posPage.addFromSearch(kopi);

    await posPage.payWith('Tunai', kopi.price - 500);

    await expect(posPage.checkoutErrors).toContainText('Uang diterima kurang dari total belanja.');
    await expect(posPage.successView).toBeHidden();
  });

  test('tutup sesi dengan selisih wajib mengisi catatan', async ({ cashSessionPage }) => {
    await cashSessionPage.close(expectedCash - shortage);

    await expect(cashSessionPage.errorFlash).toContainText(`Alasan wajib diisi karena ada selisih kas (${rupiah(-shortage)}).`);
  });

  test('tutup sesi dengan catatan menyimpan selisih kas', async ({ cashSessionPage, db }) => {
    const note = 'Uang receh kurang saat hitung fisik';

    await test.step('form tutup sesi menampilkan kas yang diharapkan', async () => {
      await cashSessionPage.gotoCloseForm();
      await expect(cashSessionPage.expectedCashText).toHaveText(`Kas diharapkan: ${rupiah(expectedCash)}`);
    });

    await test.step('tutup sesi dengan catatan', async () => {
      await cashSessionPage.close(expectedCash - shortage, note);
      await expect(cashSessionPage.successFlash).toHaveText(`Sesi kas ditutup. Selisih: ${rupiah(-shortage)}.`);
    });

    await test.step('database: selisih dan catatan tersimpan', async () => {
      expect(await db.lastClosedCashSession()).toMatchObject({
        expected_cash: decimal(expectedCash),
        actual_cash: decimal(expectedCash - shortage),
        difference: decimal(-shortage),
        note,
      });
    });
  });
});
