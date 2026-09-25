// Fungsi yang dijalankan setiap "pengguna virtual" (VU). Setiap VU login sekali,
// lalu mengulang perjalanannya sampai skenario selesai (cookie sesi disimpan per VU).
import { sleep } from 'k6';
import { CASHIER, minStock, OWNER } from '../lib/config.js';
import { ensureStock, kasirJourney, login, ownerJourney } from '../lib/partambus.js';

let loggedInAs = null;

function loginOnce(user) {
  if (loggedInAs !== user.username) {
    login(user);
    loggedInAs = user.username;
  }
}

// Stok harus cukup untuk semua transaksi di skenario itu. Kalau stok habis di
// tengah test, kasir virtual akan (dengan benar) ditolak dan hasil test jadi rancu.
export function setupStock(scenarioDefault) {
  return () => ensureStock(OWNER, minStock(scenarioDefault));
}

export function kasir() {
  loginOnce(CASHIER);
  kasirJourney();
  sleep(2 + Math.random() * 3); // jeda sebelum pelanggan berikutnya
}

export function owner() {
  loginOnce(OWNER);
  ownerJourney();
}
