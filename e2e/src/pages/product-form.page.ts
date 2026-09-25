import type { Locator, Page } from '@playwright/test';
import type { NewProduct } from '../data/factory';
import { BasePage } from './base.page';

export class ProductFormPage extends BasePage {
  readonly path = '/products/create.php';

  readonly code: Locator;
  readonly name: Locator;
  readonly baseUnit: Locator;
  readonly lowStockThreshold: Locator;
  readonly canSell: Locator;
  readonly sellingPrice: Locator;
  readonly save: Locator;

  constructor(page: Page) {
    super(page);
    this.code = page.getByLabel('Kode Produk');
    this.name = page.getByLabel('Nama Produk');
    this.baseUnit = page.getByLabel('Satuan Dasar (base unit)');
    this.lowStockThreshold = page.getByLabel('Batas Stok Rendah (dalam satuan dasar)');
    this.canSell = page.getByRole('checkbox', { name: 'Bisa dijual' }).first();
    this.sellingPrice = page.getByLabel('Harga Jual per Satuan Dasar');
    this.save = page.getByRole('button', { name: 'Simpan Produk' });
  }

  async fill(product: NewProduct, options: { sellable?: boolean } = {}): Promise<void> {
    const sellable = options.sellable ?? true;
    await this.code.fill(product.code);
    await this.name.fill(product.name);
    await this.baseUnit.selectOption(product.baseUnit);
    await this.lowStockThreshold.fill(String(product.lowStockThreshold));
    await this.canSell.setChecked(sellable);
    if (sellable) {
      await this.sellingPrice.fill(String(product.sellingPrice));
    }
  }

  async create(product: NewProduct, options: { sellable?: boolean } = {}): Promise<void> {
    await this.fill(product, options);
    await this.save.click();
  }
}
