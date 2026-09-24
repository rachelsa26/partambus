import { rupiah, seededProducts } from '../../src/data/factory';
import { expect, test } from '../../src/fixtures/test';

/**
 * Aplikasi hanya mengizinkan SATU sesi kas terbuka untuk seluruh toko.
 * Karena itu skenario kas dijalankan berurutan (serial) dari kondisi
 * awal yang dipaksa bersih: tidak ada sesi terbuka.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Sesi kas & pembayaran tunai', { tag: '@cash' }, () => {
  const openingCash = 100_000;
  const { kopi } = seededProducts;
  const qty = 3;
  const saleTotal = kopi.price * qty; // Rp6.000
  const cashReceived = 10_000;

  test.beforeAll(async ({ db }) => {
    await db.closeAllCashSessions();
  });

  test('tunai tidak bisa dipilih sebelum sesi kas dibuka', async ({ asCashier, posPage }) => {
    await asCashier.goto(posPage.path);

    await expect(posPage.noCashSessionWarning).toBeVisible();
    await expect(posPage.paymentMethod('Tunai')).toBeDisabled();
  });

  test('kasir membuka sesi kas', async ({ asCashier, cashSessionPage, db }) => {
    await asCashier.goto(cashSessionPage.path);
    await cashSessionPage.open(openingCash);

    await expect(cashSessionPage.successFlash).toHaveText(`Sesi kas dibuka dengan kas awal ${rupiah(openingCash)}.`);
    const session = await db.openCashSession();
    expect(Number(session?.opening_cash)).toBe(openingCash);
  });

  test('sesi kas kedua tidak bisa dibuka selama masih ada yang aktif', async ({ asOwner, cashSessionPage }) => {
    await asOwner.goto('/cash/open.php');

    await expect(asOwner).toHaveURL(/\/cash\/index\.php$/);
    await expect(cashSessionPage.errorFlash).toHaveText('Sudah ada sesi kas aktif.');
  });

  test('pembayaran tunai menghitung kembalian dengan benar', { tag: '@smoke' }, async ({ asCashier, posPage, db }) => {
    await asCashier.goto(posPage.path);
    await posPage.addFromSearch(kopi);
    await posPage.setQuantity(kopi.name, qty);

    await posPage.payWith('Tunai', cashReceived);
    const { saleNumber } = await posPage.expectSaleCompleted();

    await expect(posPage.successChange).toHaveText(rupiah(cashReceived - saleTotal));
    expect(await db.payment(saleNumber)).toMatchObject({
      method: 'cash',
      amount: '6000.00',
      cash_received: '10000.00',
      change_amount: '4000.00',
    });
  });

  test('uang diterima kurang dari total ditolak', async ({ asCashier, posPage }) => {
    await asCashier.goto(posPage.path);
    await posPage.addFromSearch(kopi);

    await posPage.payWith('Tunai', kopi.price - 500);

    await expect(posPage.checkoutErrors).toContainText('Uang diterima kurang dari total belanja.');
    await expect(posPage.successView).toBeHidden();
  });

  test('tutup sesi dengan selisih wajib mengisi catatan', async ({ asCashier, cashSessionPage }) => {
    const expectedCash = openingCash + saleTotal;
    await asCashier.goto(cashSessionPage.path);

    await cashSessionPage.close(expectedCash - 1_000);

    await expect(cashSessionPage.errorFlash).toContainText(`Alasan wajib diisi karena ada selisih kas (${rupiah(-1_000)}).`);
  });

  test('tutup sesi dengan catatan menyimpan selisih kas', async ({ asCashier, cashSessionPage, db }) => {
    const expectedCash = openingCash + saleTotal;
    await asCashier.goto(cashSessionPage.path);
    await asCashier.goto('/cash/close.php');
    await expect(cashSessionPage.expectedCashText).toHaveText(`Kas diharapkan: ${rupiah(expectedCash)}`);

    await cashSessionPage.close(expectedCash - 1_000, 'Uang receh kurang saat hitung fisik');

    await expect(cashSessionPage.successFlash).toHaveText(`Sesi kas ditutup. Selisih: ${rupiah(-1_000)}.`);
    expect(await db.lastClosedCashSession()).toMatchObject({
      expected_cash: `${expectedCash}.00`,
      actual_cash: `${expectedCash - 1_000}.00`,
      difference: '-1000.00',
      note: 'Uang receh kurang saat hitung fisik',
    });
  });
});
