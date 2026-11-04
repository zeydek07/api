import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { API_URL, qty } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { Movement } from '../../core/models';
import { BarChart, BarDatum } from '../../ui/bar-chart';

interface Dashboard {
  active_products: number;
  low_stock_count: number;
  usage_today: number;
  usage_last_7_days: number;
  latest_usages: Movement[];
  daily_usage: { date: string; count: number }[];
  top_products: { id: number; name: string; unit: string; total_quantity: string }[];
}

@Component({
  imports: [DatePipe, RouterLink, CanDirective, BarChart],
  templateUrl: './dashboard.html',
})
export class DashboardPage {
  private readonly http = inject(HttpClient);
  protected readonly auth = inject(AuthService);
  protected readonly data = signal<Dashboard | null>(null);
  protected readonly qty = qty;

  protected readonly greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Günaydın' : h < 18 ? 'İyi günler' : 'İyi akşamlar';
  })();

  protected readonly chart = computed<BarDatum[]>(() =>
    (this.data()?.daily_usage ?? []).map((d) => {
      const date = new Date(d.date + 'T00:00:00');
      return {
        label: date.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' }),
        tooltip: date.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long' }),
        value: d.count,
      };
    }),
  );

  protected readonly chartTotal = computed(() => (this.data()?.daily_usage ?? []).reduce((s, d) => s + d.count, 0));

  protected readonly topMax = computed(() =>
    Math.max(1, ...(this.data()?.top_products ?? []).map((p) => Number(p.total_quantity))),
  );

  constructor() {
    this.http.get<Dashboard>(`${API_URL}/dashboard`).subscribe((d) => this.data.set(d));
  }

  protected pct(v: string): number {
    return (Number(v) / this.topMax()) * 100;
  }
}
