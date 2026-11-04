import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { debounceTime } from 'rxjs';
import { API_URL, qty, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { DELIVERY_LABELS, Delivery, Hospital, Page } from '../../core/models';
import { Pagination } from '../../ui/pagination';

/** Konsinye teslimat ve geri alma belgeleri */
@Component({
  imports: [DatePipe, ReactiveFormsModule, RouterLink, CanDirective, Pagination],
  template: `
    <div class="page-head">
      <div class="flex-1">
        <h1 class="page-title">Teslimatlar</h1>
        <p class="page-sub">Hastanelere bırakılan ve hastanelerden geri alınan ürünlerin imzalı belgeleri.</p>
      </div>
      <a class="btn btn-primary" *appCan="'deliveries.create'" routerLink="/teslimat">
        <span class="icon">add</span> Yeni teslimat
      </a>
    </div>

    <div class="card">
      <form class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-6" [formGroup]="filters">
        <div class="relative lg:col-span-2">
          <span class="icon pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-muted">search</span>
          <input class="input pl-9" formControlName="q" placeholder="Belge no veya teslim alan" aria-label="Ara" />
        </div>
        <select class="select" formControlName="type" aria-label="Tür">
          <option value="">Tüm belgeler</option>
          <option value="delivery">Konsinye teslim</option>
          <option value="return">Geri alma</option>
        </select>
        @if (hospitals().length) {
          <select class="select" formControlName="hospital_id" aria-label="Hastane">
            <option value="">Tüm hastaneler</option>
            @for (h of hospitals(); track h.id) {
              <option [value]="h.id">{{ h.name }}</option>
            }
          </select>
        }
        <input class="input" type="date" formControlName="from" aria-label="Başlangıç" title="Başlangıç" />
        <input class="input" type="date" formControlName="to" aria-label="Bitiş" title="Bitiş" />
      </form>

      @if (loading()) {
        <div class="loading-bar"></div>
      }

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Belge</th>
              <th>Tarih</th>
              <th>Hastane</th>
              <th>Teslim alan / eden</th>
              <th>Personel</th>
              <th class="num">Ürün</th>
            </tr>
          </thead>
          <tbody>
            @for (d of page()?.data ?? []; track d.id) {
              <tr class="cursor-pointer" (click)="open(d)">
                <td>
                  <a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/teslimatlar', d.id]" (click)="$event.stopPropagation()">{{ d.number }}</a>
                  <div class="mt-1 flex gap-1">
                    <span class="badge" [class]="d.type === 'return' ? 'badge-warn' : 'badge-primary'">{{ labels[d.type] }}</span>
                    @if (d.status === 'cancelled') {
                      <span class="badge badge-danger">İptal</span>
                    }
                  </div>
                </td>
                <td class="whitespace-nowrap">
                  <div class="text-ink">{{ d.delivered_at | date: 'dd.MM.yyyy' }}</div>
                  <div class="cell-sub">{{ d.delivered_at | date: 'HH:mm' }}</div>
                </td>
                <td>
                  <div class="cell-title">{{ d.hospital?.name }}</div>
                  <div class="cell-sub">{{ d.location?.name }}</div>
                </td>
                <td>{{ d.receiver_name }}</td>
                <td class="whitespace-nowrap">{{ d.user?.name }}</td>
                <td class="num whitespace-nowrap">
                  <span class="font-semibold text-ink">{{ qty(d.units) }}</span>
                  <span class="cell-sub block">{{ d.lines_count }} kalem</span>
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="6" class="py-16 text-center">
                  @if (!loading()) {
                    <span class="icon text-[40px] text-muted">receipt_long</span>
                    <p class="mt-2 font-medium text-ink">Belge bulunamadı</p>
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      <app-pagination [page]="page()" (pageChange)="goTo($event)" (sizeChange)="setSize($event)" />
    </div>
  `,
})
export class DeliveriesPage {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  protected readonly qty = qty;
  protected readonly labels: Record<string, string> = DELIVERY_LABELS;

  protected readonly page = signal<Page<Delivery> | null>(null);
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly loading = signal(false);
  protected readonly filters = inject(FormBuilder).nonNullable.group({
    q: [''],
    type: [''],
    hospital_id: [''],
    from: [''],
    to: [''],
  });
  private pageNo = 1;
  private perPage = 25;

  constructor() {
    this.filters.valueChanges.pipe(debounceTime(300), takeUntilDestroyed()).subscribe(() => {
      this.pageNo = 1;
      this.load();
    });
    if (this.auth.can('hospitals.view')) {
      this.http
        .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { per_page: 100 } })
        .subscribe((p) => this.hospitals.set(p.data));
    }
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({ ...this.filters.getRawValue(), page: this.pageNo, per_page: this.perPage });
    this.http.get<Page<Delivery>>(`${API_URL}/deliveries`, { params }).subscribe({
      next: (p) => {
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected open(d: Delivery): void {
    this.router.navigate(['/teslimatlar', d.id]);
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
}
