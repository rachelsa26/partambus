import type { Locator, Page } from '@playwright/test';
import { BasePage } from './base.page';

export class DashboardPage extends BasePage {
  readonly path = '/dashboard.php';

  /** Grafik tren (SVG dari server), satu per metrik: omzet, laba, transaksi, dst. */
  readonly trendCharts: Locator;

  constructor(page: Page) {
    super(page);
    this.trendCharts = page.locator('svg.trend-chart-svg');
  }

  /** Label angka di sumbu Y sebuah grafik tren. */
  yAxisLabels(chart: Locator): Locator {
    return chart.locator('text[text-anchor="end"]');
  }
}
