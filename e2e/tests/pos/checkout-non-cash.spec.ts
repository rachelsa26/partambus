import { rupiah, seededProducts } from '../../src/data/factory';
import { expect, test } from '../../src/fixtures/test';

/**
 * Pembayaran non-tunai tidak butuh sesi kas, jadi aman dijalankan paralel.
 * Stok diverifikasi lewat ledger stock_movements milik transaksi ini
 * (bukan selisih saldo), karena test lain bisa menjual produk yang sama
 * pada saat bersamaan.
 */
test.describe('POS - pembayaran non-tunai', { tag: '@pos' }, () => {
  test.beforeEach(async ({ asCashier, posPage }) => {
    await asCashier.goto(posPage.path);
  });

  test('kasir menjual 3 item via QRIS dan stok berkurang', { tag: '@smoke' }, async ({ posPage, db, asCashier }) => {
    const { kopi } = seededProducts;

    await posPage.addFromSearch(kopi);
    await posPage.setQuantity(kopi.name, 3);
    await expect(posPage.total).toHaveText(rupiah(kopi.price * 3));

    await posPage.payWith('QRIS');
    const { saleNumber } = await posPage.expectSaleCompleted();
    await expect(posPage.successTotal).toHaveText(rupiah(kopi.price * 3));

    expect(saleNumber).toMatch(/^SL-\d{8}-\d{4}$/);
    const sale = await db.sale(saleNumber);
    expect(sale).toMatchObject({ status: 'completed', total: '6000.00' });

    const payment = await db.payment(saleNumber);
    expect(payment).toMatchObject({ method: 'qris', amount: '6000.00', cash_received: null });

    const movements = await db.saleStockMovements(saleNumber);
    expect(movements).toEqual([expect.objectContaining({ code: kopi.code, qty_delta_base: -3 })]);

    await test.step('transaksi muncul di riwayat penjualan', async () => {
      await asCashier.goto('/sales/index.php');
      await expect(asCashier.getByText(saleNumber)).toBeVisible();
    });
  });

  test('menjual per SLOP mengurangi stok sesuai faktor konversi (1 SLOP = 10 PCS)', async ({ posPage, db }) => {
    const { rokokSlop } = seededProducts;

    await posPage.addFromSearch(rokokSlop);
    await expect(posPage.total).toHaveText(rupiah(rokokSlop.price));

    await posPage.payWith('Transfer');
    const { saleNumber } = await posPage.expectSaleCompleted();

    const movements = await db.saleStockMovements(saleNumber);
    expect(movements).toEqual([expect.objectContaining({ code: rokokSlop.code, qty_delta_base: -rokokSlop.conversion })]);
  });

  test('produk dengan stok habis tidak bisa dibayar', async ({ posPage }) => {
    const { stokKosong } = seededProducts;

    await posPage.addFromSearch(stokKosong);

    await expect(posPage.stockWarning(stokKosong.name)).toContainText('Stok kurang (sisa 0)');
    await expect(posPage.payButton).toBeDisabled();
  });

  test('scan barcode langsung menambahkan produk ke keranjang', async ({ posPage }) => {
    const { kopi } = seededProducts;

    await posPage.scan(kopi.barcode);

    await expect(posPage.scanToast).toHaveText(`Ditambahkan: ${kopi.name}`);
    await expect(posPage.cartLine(kopi.name)).toBeVisible();
    await expect(posPage.searchInput).toBeEmpty(); // siap untuk scan berikutnya
  });

  test('scan produk yang stoknya habis tidak menambah ke keranjang', async ({ posPage, page }) => {
    const { stokKosong } = seededProducts;

    await posPage.scan(stokKosong.code);

    await expect(page.getByText(`Stok "${stokKosong.name}" tidak cukup (sisa 0).`)).toBeVisible();
    await expect(posPage.cartCount).toHaveText('0 item');
  });

  test('pencarian tanpa hasil menampilkan pesan yang jelas', async ({ posPage, page }) => {
    await posPage.search('KODE-TIDAK-ADA-999');

    await expect(page.getByText('Produk dengan kode/kata kunci "KODE-TIDAK-ADA-999" tidak ditemukan.')).toBeVisible();
  });
});
