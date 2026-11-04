import { Dialog } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, OnInit, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { merge } from 'rxjs';
import { API_URL, qty, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { Page, Stock, WAREHOUSE_LABELS, Warehouse } from '../../core/models';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { AdjustDialog } from './adjust-dialog';
import { StockOperation, StockOperationDialog } from './stock-operation-dialog';

@Component({
  imports: [ReactiveFormsModule, CanDirective, Pagination],
  templateUrl: './stock.html',
})
export class StockPage implements OnInit {
  /** /stok?depo=3 ile gelindiğinde ön seçim */
  readonly depo = input<string>();

  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly auth = inject(AuthService);

  protected readonly page = signal<Page<Stock> | null>(null);
  protected readonly warehouses = signal<Warehouse[]>([]);
  protected readonly loading = signal(false);
  protected readonly labels: Record<string, string> = WAREHOUSE_LABELS;
  protected readonly qty = qty;

  protected readonly warehouseId = new FormControl<string>('', { nonNullable: true });
  protected readonly lowStock = new FormControl(false, { nonNullable: true });
  private pageNo = 1;
  private perPage = 50;

  constructor() {
    merge(this.warehouseId.valueChanges, this.lowStock.valueChanges)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        this.pageNo = 1;
        this.load();
      });
    // Depo listesi için warehouses.view gerekir; yoksa tüm depoların stoğu listelenir.
    if (this.auth.can('warehouses.view')) {
      this.http.get<Warehouse[]>(`${API_URL}/warehouses`).subscribe((w) => this.warehouses.set(w));
    }
  }

  ngOnInit(): void {
    if (this.depo()) this.warehouseId.setValue(this.depo()!, { emitEvent: false });
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      warehouse_id: this.warehouseId.value,
      low_stock: this.lowStock.value || '',
      page: this.pageNo,
      per_page: this.perPage,
    });
    this.http.get<Page<Stock>>(`${API_URL}/stocks`, { params }).subscribe({
      next: (p) => {
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected goTo(page: number): void {
    this.pageNo = page;
    this.load();
  }

  protected setSize(size: number): void {
    this.perPage = size;
    this.pageNo = 1;
    this.load();
  }

  protected isLow(s: Stock): boolean {
    const min = s.product?.min_stock_level ?? 0;
    return min > 0 && Number(s.quantity) <= min;
  }

  /** Minimum seviyeye göre doluluk (gösterge çubuğu için); minimumun 3 katı = dolu */
  protected level(s: Stock): number {
    const min = s.product?.min_stock_level ?? 0;
    if (!min) return 100;
    return Math.min(100, (Number(s.quantity) / (min * 3)) * 100);
  }

  protected operate(mode: StockOperation): void {
    openModal<boolean>(
      this.dialog,
      StockOperationDialog,
      { mode, warehouses: this.warehouses(), warehouseId: Number(this.warehouseId.value) || null },
      'xl',
    ).subscribe((done) => done && this.load());
  }

  protected adjust(stock?: Stock): void {
    openModal<boolean>(this.dialog, AdjustDialog, { stock, warehouses: this.warehouses() }, 'sm').subscribe(
      (done) => done && this.load(),
    );
  }
}
