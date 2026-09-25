import { randomBytes } from 'node:crypto';

/**
 * Semua data yang dibuat test diberi suffix unik, sehingga test bisa
 * jalan paralel dan berulang kali tanpa bentrok (tidak perlu reset DB
 * di antara test).
 */
export function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${randomBytes(2).toString('hex')}`.toUpperCase();
}

export interface NewProduct {
  code: string;
  name: string;
  baseUnit: string;
  sellingPrice: number;
  lowStockThreshold: number;
}

export function buildProduct(overrides: Partial<NewProduct> = {}): NewProduct {
  const suffix = uniqueSuffix();
  return {
    code: `E2E-${suffix}`,
    name: `Produk E2E ${suffix}`,
    baseUnit: 'PCS',
    sellingPrice: 3500,
    lowStockThreshold: 5,
    ...overrides,
  };
}

/** Produk & harga dari docker/db/02-test-data.sql */
export const seededProducts = {
  kopi: { code: 'QA-KOPI', barcode: '8990000000011', name: 'QA Kopi Sachet', unit: 'PCS', price: 2000 },
  rokokPcs: { code: 'QA-ROKOK', name: 'QA Rokok Filter', unit: 'PCS', price: 2500 },
  rokokSlop: { code: 'QA-ROKOK', name: 'QA Rokok Filter', unit: 'SLOP', price: 24000, conversion: 10 },
  stokKosong: { code: 'QA-HABIS', name: 'QA Stok Kosong', unit: 'PCS', price: 5000 },
} as const;

/** Format rupiah persis seperti helper rupiah() di aplikasi: Rp12.500, dan -Rp1.000 untuk nilai negatif. */
export function rupiah(amount: number): string {
  const value = Math.round(amount);
  return `${value < 0 ? '-' : ''}Rp${Math.abs(value).toLocaleString('id-ID')}`;
}

/** Format angka seperti kolom DECIMAL(15,2) yang dikembalikan MySQL: 6000 -> "6000.00". */
export function decimal(amount: number): string {
  return amount.toFixed(2);
}
