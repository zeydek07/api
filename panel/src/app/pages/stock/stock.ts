import { Dialog } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, OnInit, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { debounceTime, merge } from 'rxjs';
import { API_URL, qty, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { Page, Stock, WAREHOUSE_LABELS, Warehouse } from '../../core/models';
import { EXPIRY_CRITICAL_DAYS, EXPIRY_WARNING_DAYS, ExpiryBadge } from '../../ui/expiry-badge';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { AdjustDialog } from './adjust-dialog';
import { StockOperation, StockOperationDialog } from './stock-operation-dialog';

type ExpiryFilter = '' | 'warning' | 'critical' | 'expired';

@Component({
  imports: [ReactiveFormsModule, CanDirective, Pagination, ExpiryBadge],
  templateUrl: './stock.html',
})
export class StockPage implements OnInit {
  /** /stok?depo=3 ile gelindiğinde ön seçim */
  readonly depo = input<string>();
  /** /stok?skt=warning ile gelindiğinde SKT filtresi */
  readonly skt = input<string>();

  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly auth = inject(AuthService);

  protected readonly page = signal<Page<Stock> | null>(null);
  protected readonly warehouses = signal<Warehouse[]>([]);
  protected readonly loading = signal(false);
  protected readonly labels: Record<string, string> = WAREHOUSE_LABELS;
  protected readonly qty = qty;

  protected readonly warehouseId = new FormControl<string>('', { nonNullable: true });
  protected readonly q = new FormControl('', { nonNullable: true });
  protected readonly expiry = signal<ExpiryFilter>('');
  protected readonly expiryOptions: { v: ExpiryFilter; l: string }[] = [
    { v: '', l: 'Tümü' },
    { v: 'warning', l: `SKT yaklaşan (${EXPIRY_WARNING_DAYS} gün)` },
    { v: 'critical', l: `Kritik (${EXPIRY_CRITICAL_DAYS} gün)` },
    { v: 'expired', l: 'SKT geçmiş' },
  ];
  private pageNo = 1;
  private perPage = 50;

  constructor() {
    merge(this.warehouseId.valueChanges, this.q.valueChanges.pipe(debounceTime(300)))
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.reset());
    // Depo listesi için warehouses.view gerekir; yoksa tüm depoların stoğu listelenir.
    if (this.auth.can('warehouses.view')) {
      this.http.get<Warehouse[]>(`${API_URL}/warehouses`).subscribe((w) => this.warehouses.set(w));
    }
  }

  ngOnInit(): void {
    if (this.depo()) this.warehouseId.setValue(this.depo()!, { emitEvent: false });
    const skt = this.skt() as ExpiryFilter;
    if (['warning', 'critical', 'expired'].includes(skt)) this.expiry.set(skt);
    this.load();
  }

  protected setExpiry(v: ExpiryFilter): void {
    this.expiry.set(v);
    this.reset();
  }

  private reset(): void {
    this.pageNo = 1;
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      warehouse_id: this.warehouseId.value,
      q: this.q.value,
      expiry: this.expiry(),
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
