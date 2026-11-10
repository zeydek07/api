import { Dialog } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { debounceTime } from 'rxjs';
import { API_URL, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CASE_STATUS_BADGES, CASE_STATUS_LABELS, CaseStatus, Hospital, Page, SurgeryCase } from '../../core/models';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { PlanCaseDialog } from './case-dialogs';

/** Ameliyat vakaları: planlandı, onay bekliyor, onaylandı, iptal */
@Component({
  imports: [DatePipe, ReactiveFormsModule, RouterLink, Pagination],
  template: `
    <div class="page-head">
      <div class="flex-1">
        <h1 class="page-title">Vakalar</h1>
        <p class="page-sub">Planlanan ameliyatlar ve vakada kullanılan ürünler. Ürünler ameliyat sırasında mobil uygulamada kaydedilir.</p>
      </div>
      @if (canPlan) {
        <button class="btn btn-primary" (click)="plan()"><span class="icon">add</span> Vaka planla</button>
      }
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Vaka durumu">
      @for (t of tabs; track t.key) {
        <button
          role="tab"
          class="-mb-px shrink-0 border-b-2 px-4 pb-3 text-sm font-medium transition"
          [class]="status() === t.key ? 'border-primary text-ink' : 'border-transparent text-muted hover:text-ink'"
          [attr.aria-selected]="status() === t.key"
          (click)="setStatus(t.key)"
        >
          {{ t.label }}
        </button>
      }
    </div>

    <div class="card">
      <form class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-5" [formGroup]="filters">
        <div class="relative">
          <span class="icon pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-muted">search</span>
          <input class="input pl-9" formControlName="q" placeholder="VK no, protokol veya ameliyat" aria-label="Ara" />
        </div>
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

      @if (failed()) {
        <div class="flex items-center gap-3 border-b border-line bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
          <span class="icon">error</span>
          <span class="flex-1">Vakalar yüklenemedi.</span>
          <button class="btn btn-secondary btn-sm" (click)="load()">Yeniden dene</button>
        </div>
      }

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Vaka</th>
              <th>Tarih</th>
              <th>Hastane</th>
              <th>Doktor</th>
              <th>Ameliyat</th>
              <th>Personel</th>
              <th>Durum</th>
              <th>Ürünler</th>
            </tr>
          </thead>
          <tbody>
            @for (c of page()?.data ?? []; track c.id) {
              <tr class="cursor-pointer" (click)="open(c)">
                <td>
                  <a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/vakalar', c.id]" (click)="$event.stopPropagation()">{{ c.number }}</a>
                  @if (c.is_unplanned) {
                    <span class="badge badge-warn ms-1.5">Plansız</span>
                  }
                  @if (c.protocol_no) {
                    <div class="cell-sub">Protokol {{ c.protocol_no }}</div>
                  }
                </td>
                <td class="whitespace-nowrap">
                  <div class="text-ink">{{ c.scheduled_at | date: 'dd.MM.yyyy' }}</div>
                  <div class="cell-sub">{{ c.scheduled_at | date: 'HH:mm' }}</div>
                </td>
                <td>
                  <div class="cell-title">{{ c.hospital?.name }}</div>
                  <div class="cell-sub">{{ c.location?.path || c.location?.name }}</div>
                </td>
                <td>{{ c.doctor?.display_name || '—' }}</td>
                <td>{{ c.procedure || '—' }}</td>
                <td class="whitespace-nowrap">{{ c.user?.name }}</td>
                <td>
                  @if (c.is_overdue) {
                    <span class="badge badge-danger"><span class="icon text-[13px]">schedule</span> Kaydı gecikti</span>
                  } @else {
                    <span class="badge" [class]="badges[c.status]">{{ labels[c.status] }}</span>
                  }
                </td>
                <td class="text-sm">
                  @if (c.status === 'planned' || c.status === 'cancelled' && !c.items_count) {
                    <span class="text-muted">—</span>
                  } @else {
                    <div class="text-ink">{{ c.billable_count }} faturalanacak</div>
                    @if (c.billable_count) {
                      <div class="cell-sub">{{ c.invoiced_count }}/{{ c.billable_count }} faturalandı</div>
                    }
                    @if ((c.items_count ?? 0) > (c.billable_count ?? 0)) {
                      <div class="cell-sub">{{ (c.items_count ?? 0) - (c.billable_count ?? 0) }} kullanılmadı</div>
                    }
                  }
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="8" class="py-16 text-center">
                  @if (!loading() && !failed()) {
                    <span class="icon text-[40px] text-muted">medical_services</span>
                    <p class="mt-2 font-medium text-ink">Vaka bulunamadı</p>
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
export class CasesPage {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly auth = inject(AuthService);

  protected readonly labels = CASE_STATUS_LABELS;
  protected readonly badges = CASE_STATUS_BADGES;
  protected readonly canPlan = this.auth.can(['cases.create', 'cases.approve']);
  protected readonly tabs: { key: CaseStatus | ''; label: string }[] = [
    { key: '', label: 'Tümü' },
    { key: 'planned', label: 'Planlandı' },
    { key: 'recorded', label: 'Onay bekliyor' },
    { key: 'approved', label: 'Onaylandı' },
    { key: 'cancelled', label: 'İptal' },
  ];

  protected readonly status = signal<CaseStatus | ''>('');
  protected readonly page = signal<Page<SurgeryCase> | null>(null);
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly filters = inject(FormBuilder).nonNullable.group({ q: [''], hospital_id: [''], from: [''], to: [''] });
  private pageNo = 1;
  private perPage = 25;
  /** Eski isteğin yanıtı yenisini ezmesin */
  private seq = 0;

  constructor() {
    this.filters.valueChanges.pipe(debounceTime(300), takeUntilDestroyed()).subscribe(() => {
      this.pageNo = 1;
      this.load();
    });
    if (this.auth.can('hospitals.view')) {
      this.http
        .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { per_page: 100 } })
        .subscribe({ next: (p) => this.hospitals.set(p.data), error: () => {} });
    }
    this.load();
  }

  protected load(): void {
    const n = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    const params = toParams({ ...this.filters.getRawValue(), status: this.status(), page: this.pageNo, per_page: this.perPage });
    this.http.get<Page<SurgeryCase>>(`${API_URL}/cases`, { params }).subscribe({
      next: (p) => {
        if (n !== this.seq) return;
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => {
        if (n !== this.seq) return;
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected setStatus(s: CaseStatus | ''): void {
    this.status.set(s);
    this.pageNo = 1;
    this.load();
  }

  protected plan(): void {
    openModal<SurgeryCase>(this.dialog, PlanCaseDialog, {}, 'lg').subscribe((c) => c && this.router.navigate(['/vakalar', c.id]));
  }

  protected open(c: SurgeryCase): void {
    this.router.navigate(['/vakalar', c.id]);
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
