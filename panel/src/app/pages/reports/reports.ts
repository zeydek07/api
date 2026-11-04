import { HttpClient } from '@angular/common/http';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { debounceTime } from 'rxjs';
import { API_URL, qty, toParams } from '../../core/api';

interface UsageRow {
  hospital_id?: number;
  hospital_name?: string;
  city?: string;
  user_id?: number;
  user_name?: string;
  product_id?: number;
  product_name?: string;
  code?: string;
  unit?: string;
  total_quantity: string;
  line_count: number;
}

interface LowStockRow {
  id: number;
  name: string;
  code: string;
  unit: string;
  min_stock_level: number;
  total_stock: string;
}

type GroupBy = 'user' | 'product' | 'user_product' | 'hospital' | 'hospital_product';

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

@Component({
  imports: [ReactiveFormsModule],
  templateUrl: './reports.html',
})
export class ReportsPage implements OnInit {
  /** ?tab=kritik ile kritik stok sekmesi açılır */
  readonly tab = input<string>();

  private readonly http = inject(HttpClient);
  protected readonly qty = qty;

  protected readonly active = signal<'usage' | 'low'>('usage');
  protected readonly usage = signal<UsageRow[]>([]);
  protected readonly lowStock = signal<LowStockRow[] | null>(null);
  protected readonly loading = signal(false);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    from: [isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1))],
    to: [isoDate(new Date())],
    group_by: ['user_product' as GroupBy],
  });

  protected readonly groupBy = toSignal(this.form.controls.group_by.valueChanges, {
    initialValue: this.form.controls.group_by.value,
  });

  protected readonly totalLines = computed(() => this.usage().reduce((s, r) => s + Number(r.line_count), 0));
  protected readonly groupOptions: { v: GroupBy; l: string }[] = [
    { v: 'user_product', l: 'Personel + ürün' },
    { v: 'user', l: 'Personel' },
    { v: 'product', l: 'Ürün' },
    { v: 'hospital', l: 'Hastane' },
    { v: 'hospital_product', l: 'Hastane + ürün' },
  ];

  protected hasUser(): boolean {
    return this.groupBy() === 'user' || this.groupBy() === 'user_product';
  }

  protected hasProduct(): boolean {
    return this.groupBy() !== 'user' && this.groupBy() !== 'hospital';
  }

  protected hasHospital(): boolean {
    return this.groupBy().startsWith('hospital');
  }

  protected colCount(): number {
    return (this.hasUser() ? 1 : 0) + (this.hasProduct() ? 1 : 0) + (this.hasHospital() ? 1 : 0);
  }
  protected readonly presets = [
    { l: 'Bu ay', days: -1 },
    { l: 'Son 7 gün', days: 7 },
    { l: 'Son 30 gün', days: 30 },
  ];

  constructor() {
    this.form.valueChanges.pipe(debounceTime(250), takeUntilDestroyed()).subscribe(() => this.loadUsage());
    this.loadUsage();
  }

  ngOnInit(): void {
    if (this.tab() === 'kritik') this.show('low');
  }

  protected show(tab: 'usage' | 'low'): void {
    this.active.set(tab);
    if (tab === 'low' && this.lowStock() === null) {
      this.http.get<LowStockRow[]>(`${API_URL}/reports/low-stock`).subscribe((r) => this.lowStock.set(r));
    }
  }

  protected preset(days: number): void {
    const now = new Date();
    const from =
      days < 0 ? new Date(now.getFullYear(), now.getMonth(), 1) : new Date(now.getTime() - (days - 1) * 86400000);
    this.form.patchValue({ from: isoDate(from), to: isoDate(now) });
  }

  protected loadUsage(): void {
    this.loading.set(true);
    this.http
      .get<{ rows: UsageRow[] }>(`${API_URL}/reports/usage`, { params: toParams(this.form.getRawValue()) })
      .subscribe({
        next: (r) => {
          this.usage.set(r.rows);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  /** Raporu Excel'de açılabilir CSV olarak indirir */
  protected exportCsv(): void {
    const header = [
      ...(this.hasHospital() ? ['Hastane', 'İl'] : []),
      ...(this.hasUser() ? ['Personel'] : []),
      ...(this.hasProduct() ? ['Ürün', 'Kod', 'Birim'] : []),
      'Toplam miktar',
      'Kayıt sayısı',
    ];
    const lines = this.usage().map((r) => [
      ...(this.hasHospital() ? [r.hospital_name, r.city] : []),
      ...(this.hasUser() ? [r.user_name] : []),
      ...(this.hasProduct() ? [r.product_name, r.code, r.unit] : []),
      qty(r.total_quantity),
      r.line_count,
    ]);
    const csv = [header, ...lines]
      .map((row) => row.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(';'))
      .join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const { from, to } = this.form.getRawValue();
    a.download = `kullanim-raporu-${from}-${to}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  }
}
