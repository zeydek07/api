import { DIALOG_DATA, Dialog, DialogRef } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, FormsModule, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { debounceTime } from 'rxjs';
import { API_URL, errorMessage, qty, toParams } from '../../core/api';
import { BillingItem, CASE_ITEM_LABELS, Hospital, Page } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { ModalFrame } from '../../ui/modal-frame';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';

interface BillingResponse {
  items: BillingItem[];
  summary: { lines: number; units: number; cases: number; hospitals: number };
}

interface HospitalGroup {
  hospitalId: number;
  name: string;
  items: BillingItem[];
}

interface InvoiceGroup {
  key: string;
  number: string;
  date: string | null;
  hospitals: string;
  invoicer: string;
  items: BillingItem[];
}

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** "Faturalandı işaretle" penceresi */
@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal title="Faturalandı olarak işaretle" [subtitle]="data.items.length + ' satır · ' + units() + ' adet'">
      <div class="space-y-3">
        @if (hospitalCount() > 1) {
          <div class="flex gap-3 rounded-xl bg-warn-soft p-3.5 text-sm text-warn" role="status">
            <span class="icon">info</span>
            <p>Seçimde {{ hospitalCount() }} hastane var; genelde her fatura tek hastaneye kesilir.</p>
          </div>
        }
        <div>
          <label class="label" for="bi-no">Fatura no *</label>
          <input id="bi-no" class="input font-mono" [(ngModel)]="number" maxlength="60" (keydown.enter)="save()" />
        </div>
        <div>
          <label class="label" for="bi-date">Fatura tarihi *</label>
          <input id="bi-date" class="input" type="date" [(ngModel)]="date" [max]="max" />
        </div>
        @if (error()) {
          <p class="form-error" role="alert">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-primary" [disabled]="!valid() || saving()" (click)="save()">
          {{ saving() ? 'Kaydediliyor…' : 'Faturalandı işaretle' }}
        </button>
      </div>
    </app-modal>
  `,
})
export class InvoiceDialog {
  protected readonly data = inject<{ items: BillingItem[] }>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly max = today();
  protected number = '';
  protected date = today();
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected hospitalCount(): number {
    return new Set(this.data.items.map((i) => i.surgery_case?.hospital_id)).size;
  }

  protected units(): string {
    return qty(this.data.items.reduce((s, i) => s + Number(i.quantity), 0));
  }

  protected valid(): boolean {
    return this.number.trim().length > 0 && !!this.date && this.date <= this.max;
  }

  protected save(): void {
    if (!this.valid() || this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    this.http
      .post<{ message: string }>(`${API_URL}/billing/invoice`, {
        item_ids: this.data.items.map((i) => i.id),
        invoice_number: this.number.trim(),
        invoice_date: this.date,
      })
      .subscribe({
        next: (r) => {
          this.toast.success(r.message);
          this.ref.close(true);
        },
        error: (e) => {
          this.error.set(errorMessage(e));
          this.saving.set(false);
        },
      });
  }
}

/** Faturalama: onaylı vakaların kullanılan/açılan ürünleri */
@Component({
  imports: [DatePipe, ReactiveFormsModule, RouterLink],
  template: `
    <div class="page-head">
      <div class="flex-1">
        <h1 class="page-title">Faturalama</h1>
        <p class="page-sub">Onaylanan vakalarda kullanılan ve açılan ürünler. Faturayı kestikten sonra satırları faturalandı olarak işaretleyin.</p>
      </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      @for (s of cards(); track s.label) {
        <div class="card p-4">
          <div class="text-xs text-muted">{{ s.label }}</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ s.value }}</div>
        </div>
      }
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Fatura durumu">
      @for (t of tabs; track t.key) {
        <button
          role="tab"
          class="-mb-px shrink-0 border-b-2 px-4 pb-3 text-sm font-medium transition"
          [class]="tab() === t.key ? 'border-primary text-ink' : 'border-transparent text-muted hover:text-ink'"
          [attr.aria-selected]="tab() === t.key"
          (click)="setTab(t.key)"
        >
          {{ t.label }}
        </button>
      }
    </div>

    <div class="card">
      <form class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-4" [formGroup]="filters">
        <div class="relative">
          <span class="icon pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-muted">search</span>
          <input class="input pl-9" formControlName="q" placeholder="VK no, ürün, lot/seri, fatura no" aria-label="Ara" />
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
          <span class="flex-1">Liste yüklenemedi.</span>
          <button class="btn btn-secondary btn-sm" (click)="load()">Yeniden dene</button>
        </div>
      }

      @if (actionError()) {
        <p class="form-error m-4" role="alert">{{ actionError() }}</p>
      }

      @if (tab() === 'pending') {
        @for (g of hospitalGroups(); track g.hospitalId) {
          <section class="border-b border-line last:border-b-0" [attr.aria-label]="g.name">
            <div class="flex items-center gap-3 bg-surface-2 px-4 py-2.5">
              <input
                type="checkbox"
                class="size-4 cursor-pointer"
                [checked]="groupState(g) === 'all'"
                [indeterminate]="groupState(g) === 'some'"
                (change)="toggleGroup(g, $any($event.target).checked)"
                [attr.aria-label]="g.name + ' tüm satırları seç'"
              />
              <h2 class="flex-1 text-sm font-semibold text-ink">{{ g.name }}</h2>
              <span class="badge">{{ g.items.length }} satır</span>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th class="w-10"><span class="sr-only">Seç</span></th>
                    <th>Vaka</th>
                    <th>Tarih</th>
                    <th>Doktor</th>
                    <th>Ürün</th>
                    <th>Lot / Seri</th>
                    <th>Durum</th>
                    <th class="num">Adet</th>
                  </tr>
                </thead>
                <tbody>
                  @for (i of g.items; track i.id) {
                    <tr [class.bg-primary-soft]="selected().has(i.id)">
                      <td>
                        <input
                          type="checkbox"
                          class="size-4 cursor-pointer"
                          [checked]="selected().has(i.id)"
                          (change)="toggle(i.id, $any($event.target).checked)"
                          [attr.aria-label]="i.surgery_case?.number + ' ' + i.product?.name + ' seç'"
                        />
                      </td>
                      <td>
                        <a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/vakalar', i.surgery_case?.id]">{{ i.surgery_case?.number }}</a>
                        @if (i.surgery_case?.patient_name) {
                          <div class="cell-sub">{{ i.surgery_case?.patient_name }}</div>
                        }
                      </td>
                      <td class="whitespace-nowrap">{{ i.surgery_case?.performed_at | date: 'dd.MM.yyyy' }}</td>
                      <td>{{ i.surgery_case?.doctor?.display_name || '—' }}</td>
                      <td>
                        <div class="cell-title">{{ i.product?.name }}</div>
                        <div class="cell-sub font-mono">{{ i.product?.code }}</div>
                      </td>
                      <td class="font-mono text-[13px]">{{ lotSerial(i) }}</td>
                      <td><span class="badge badge-primary">{{ itemLabels[i.status] }}</span></td>
                      <td class="num whitespace-nowrap font-semibold text-ink">{{ qty(i.quantity) }}<span class="ms-1 font-normal text-muted">{{ i.product?.unit }}</span></td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </section>
        } @empty {
          <div class="py-16 text-center">
            @if (!loading() && !failed()) {
              <span class="icon text-[40px] text-muted">task_alt</span>
              <p class="mt-2 font-medium text-ink">Faturalanacak satır yok</p>
              <p class="text-sm text-muted">Onaylanan vakaların ürünleri burada görünür.</p>
            }
          </div>
        }
      } @else {
        @for (g of invoiceGroups(); track g.key) {
          <section class="border-b border-line last:border-b-0" [attr.aria-label]="'Fatura ' + g.number">
            <div class="flex flex-wrap items-center gap-3 bg-surface-2 px-4 py-2.5">
              <h2 class="text-sm font-semibold text-ink">Fatura <span class="font-mono">{{ g.number }}</span></h2>
              <span class="text-sm text-muted">{{ g.date | date: 'dd.MM.yyyy' }} · {{ g.hospitals }} · {{ g.invoicer }}</span>
              <span class="flex-1"></span>
              <span class="badge">{{ g.items.length }} satır</span>
              <button class="btn btn-ghost btn-sm" [disabled]="busy()" (click)="undo(g)"><span class="icon">undo</span> İşareti geri al</button>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Vaka</th>
                    <th>Tarih</th>
                    <th>Hastane</th>
                    <th>Ürün</th>
                    <th>Lot / Seri</th>
                    <th>Durum</th>
                    <th class="num">Adet</th>
                  </tr>
                </thead>
                <tbody>
                  @for (i of g.items; track i.id) {
                    <tr>
                      <td><a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/vakalar', i.surgery_case?.id]">{{ i.surgery_case?.number }}</a></td>
                      <td class="whitespace-nowrap">{{ i.surgery_case?.performed_at | date: 'dd.MM.yyyy' }}</td>
                      <td>{{ i.surgery_case?.hospital?.name }}</td>
                      <td>
                        <div class="cell-title">{{ i.product?.name }}</div>
                        <div class="cell-sub font-mono">{{ i.product?.code }}</div>
                      </td>
                      <td class="font-mono text-[13px]">{{ lotSerial(i) }}</td>
                      <td><span class="badge badge-primary">{{ itemLabels[i.status] }}</span></td>
                      <td class="num whitespace-nowrap font-semibold text-ink">{{ qty(i.quantity) }}<span class="ms-1 font-normal text-muted">{{ i.product?.unit }}</span></td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </section>
        } @empty {
          <div class="py-16 text-center">
            @if (!loading() && !failed()) {
              <span class="icon text-[40px] text-muted">receipt_long</span>
              <p class="mt-2 font-medium text-ink">Faturalanmış satır yok</p>
            }
          </div>
        }
      }
    </div>

    @if (tab() === 'pending' && selected().size) {
      <div class="sticky bottom-4 z-10 mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 shadow-xl shadow-black/10" role="region" aria-label="Seçili satırlar">
        <span class="flex-1 text-sm font-medium text-ink"><span class="tabular-nums">{{ selected().size }}</span> satır seçildi</span>
        <button class="btn btn-ghost btn-sm" (click)="selected.set(emptySet)">Seçimi temizle</button>
        <button class="btn btn-primary" (click)="invoice()"><span class="icon">receipt_long</span> Faturalandı işaretle</button>
      </div>
    }
  `,
})
export class BillingPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);

  protected readonly qty = qty;
  protected readonly itemLabels: Record<string, string> = CASE_ITEM_LABELS;
  protected readonly emptySet = new Set<number>();
  protected readonly tabs = [
    { key: 'pending' as const, label: 'Faturalanacaklar' },
    { key: 'invoiced' as const, label: 'Faturalananlar' },
  ];

  protected readonly tab = signal<'pending' | 'invoiced'>('pending');
  protected readonly data = signal<BillingResponse | null>(null);
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly selected = signal<Set<number>>(new Set());
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly busy = signal(false);
  protected readonly actionError = signal('');
  protected readonly filters = inject(FormBuilder).nonNullable.group({ q: [''], hospital_id: [''], from: [''], to: [''] });
  /** Eski isteğin yanıtı yenisini ezmesin */
  private seq = 0;

  protected readonly cards = computed(() => {
    const s = this.data()?.summary;
    const fmt = (n?: number) => (s ? qty(n) : '—');
    return [
      { label: this.tab() === 'pending' ? 'Bekleyen satır' : 'Faturalanan satır', value: fmt(s?.lines) },
      { label: 'Adet', value: fmt(s?.units) },
      { label: 'Vaka', value: fmt(s?.cases) },
      { label: 'Hastane', value: fmt(s?.hospitals) },
    ];
  });

  protected readonly hospitalGroups = computed<HospitalGroup[]>(() => {
    const map = new Map<number, HospitalGroup>();
    for (const i of this.data()?.items ?? []) {
      const id = i.surgery_case?.hospital_id ?? 0;
      let g = map.get(id);
      if (!g) map.set(id, (g = { hospitalId: id, name: i.surgery_case?.hospital?.name ?? 'Hastane', items: [] }));
      g.items.push(i);
    }
    return [...map.values()];
  });

  protected readonly invoiceGroups = computed<InvoiceGroup[]>(() => {
    const map = new Map<string, InvoiceGroup>();
    for (const i of this.data()?.items ?? []) {
      const key = `${i.invoice_number}|${i.invoice_date}`;
      let g = map.get(key);
      if (!g) {
        map.set(key, (g = { key, number: i.invoice_number ?? '—', date: i.invoice_date, hospitals: '', invoicer: i.invoicer?.name ?? '—', items: [] }));
      }
      g.items.push(i);
    }
    for (const g of map.values()) {
      g.hospitals = [...new Set(g.items.map((i) => i.surgery_case?.hospital?.name).filter(Boolean))].join(', ');
    }
    return [...map.values()];
  });

  constructor() {
    this.filters.valueChanges.pipe(debounceTime(300), takeUntilDestroyed()).subscribe(() => this.load());
    this.http
      .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { per_page: 100 } })
      .subscribe({ next: (p) => this.hospitals.set(p.data), error: () => {} });
    this.load();
  }

  protected load(): void {
    const n = ++this.seq;
    this.loading.set(true);
    this.failed.set(false);
    const params = toParams({ ...this.filters.getRawValue(), status: this.tab() });
    this.http.get<BillingResponse>(`${API_URL}/billing/items`, { params }).subscribe({
      next: (r) => {
        if (n !== this.seq) return;
        this.data.set(r);
        // Artık listede olmayan satırlar seçimden çıkar
        const ids = new Set(r.items.map((i) => i.id));
        this.selected.update((s) => new Set([...s].filter((id) => ids.has(id))));
        this.loading.set(false);
      },
      error: () => {
        if (n !== this.seq) return;
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected setTab(t: 'pending' | 'invoiced'): void {
    if (t === this.tab()) return;
    this.tab.set(t);
    this.data.set(null);
    this.selected.set(new Set());
    this.actionError.set('');
    this.load();
  }

  protected lotSerial(i: BillingItem): string {
    return [i.serial_number ? `SN ${i.serial_number}` : '', i.lot_number ? `LOT ${i.lot_number}` : ''].filter(Boolean).join(' · ') || '—';
  }

  protected toggle(id: number, on: boolean): void {
    this.selected.update((s) => {
      const next = new Set(s);
      if (on) next.add(id);
      else next.delete(id);
      return next;
    });
  }

  protected groupState(g: HospitalGroup): 'none' | 'some' | 'all' {
    const n = g.items.filter((i) => this.selected().has(i.id)).length;
    return n === 0 ? 'none' : n === g.items.length ? 'all' : 'some';
  }

  protected toggleGroup(g: HospitalGroup, on: boolean): void {
    this.selected.update((s) => {
      const next = new Set(s);
      for (const i of g.items) {
        if (on) next.add(i.id);
        else next.delete(i.id);
      }
      return next;
    });
  }

  protected invoice(): void {
    const items = (this.data()?.items ?? []).filter((i) => this.selected().has(i.id));
    if (!items.length) return;
    this.actionError.set('');
    openModal<boolean>(this.dialog, InvoiceDialog, { items }, 'md').subscribe((ok) => {
      if (!ok) return;
      this.selected.set(new Set());
      this.load();
    });
  }

  protected undo(g: InvoiceGroup): void {
    confirm(this.dialog, {
      title: 'Faturalama işaretini geri al',
      message: `${g.number} numaralı faturadaki ${g.items.length} satır tekrar faturalanacaklara döner.`,
      confirmText: 'İşareti geri al',
      danger: true,
    }).subscribe((ok) => {
      if (!ok || this.busy()) return;
      this.busy.set(true);
      this.actionError.set('');
      this.http.post<{ message: string }>(`${API_URL}/billing/uninvoice`, { item_ids: g.items.map((i) => i.id) }).subscribe({
        next: (r) => {
          this.toast.success(r.message);
          this.busy.set(false);
          this.load();
        },
        error: (e) => {
          this.actionError.set(errorMessage(e));
          this.busy.set(false);
        },
      });
    });
  }
}
