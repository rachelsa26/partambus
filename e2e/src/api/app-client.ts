import { expect, type APIRequestContext } from '@playwright/test';
import type { Credentials } from '../config/env';

/**
 * Klien HTTP tipis untuk aplikasi PHP berbasis form + CSRF token.
 *
 * Dipakai untuk menyiapkan kondisi test (login, buka sesi kas, dll.)
 * tanpa lewat UI, sehingga test UI hanya menguji hal yang memang
 * sedang diuji. Jika dipakai dengan `page.request` / `context.request`,
 * cookie sesi otomatis ikut dipakai oleh browser.
 */
export class AppClient {
  constructor(private readonly request: APIRequestContext) {}

  /** Ambil CSRF token dari form di halaman `path`. */
  async csrfToken(path: string): Promise<string> {
    const response = await this.request.get(path);
    expect(response.ok(), `GET ${path} gagal (${response.status()})`).toBeTruthy();
    const html = await response.text();
    const match = html.match(/name="csrf_token" value="([a-f0-9]+)"/);
    if (!match) {
      throw new Error(`CSRF token tidak ditemukan di ${path}. Apakah sesi sudah login?`);
    }
    return match[1];
  }

  /** Kirim form POST dengan CSRF token yang valid. Redirect tidak diikuti. */
  async submitForm(path: string, fields: Record<string, string>) {
    const csrf_token = await this.csrfToken(path);
    return this.request.post(path, {
      form: { csrf_token, ...fields },
      maxRedirects: 0,
    });
  }

  async login(user: Credentials): Promise<void> {
    const response = await this.submitForm('/auth/login.php', {
      username: user.username,
      password: user.password,
    });
    expect(response.status(), `Login API gagal untuk ${user.username}`).toBe(302);
    expect(response.headers()['location']).toContain('/dashboard.php');
  }

  async openCashSession(openingCash: number): Promise<void> {
    const response = await this.submitForm('/cash/open.php', {
      opening_cash: String(openingCash),
    });
    expect(response.status(), 'Gagal membuka sesi kas via API').toBe(302);
  }
}
