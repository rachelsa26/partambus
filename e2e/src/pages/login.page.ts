import type { Locator, Page } from '@playwright/test';
import type { Credentials } from '../config/env';

export class LoginPage {
  readonly path = '/auth/login.php';
  readonly username: Locator;
  readonly password: Locator;
  readonly submit: Locator;
  readonly error: Locator;

  constructor(private readonly page: Page) {
    this.username = page.getByLabel('Username');
    this.password = page.getByLabel('Password');
    this.submit = page.getByRole('button', { name: 'Masuk' });
    this.error = page.locator('.flash-error');
  }

  async goto(): Promise<void> {
    await this.page.goto(this.path);
  }

  async login(user: Pick<Credentials, 'username' | 'password'>): Promise<void> {
    await this.username.fill(user.username);
    await this.password.fill(user.password);
    await this.submit.click();
  }
}
