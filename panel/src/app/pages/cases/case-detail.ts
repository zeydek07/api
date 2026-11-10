import { Dialog } from '@angular/cdk/dialog';
import { DatePipe, NgTemplateOutlet } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, OnDestroy, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage, qty } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CASE_ITEM_LABELS, CASE_STATUS_BADGES, CASE_STATUS_LABELS, CaseEvent, SurgeryCase, SurgeryCaseItem } from '../../core/models';
import { LOCATION_ERROR_LABELS } from '../../core/location';
import { confirm } from '../../ui/confirm';
import { ExpiryBadge } from '../../ui/expiry-badge';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';
import { PhotoGallery } from '../../ui/photo-gallery';
import { CancelCaseDialog, EmailCaseDialog, PlanCaseDialog, TransferCaseDialog } from './case-dialogs';

/** Vaka kartı: plan, kullanılan ürünler, imza ve onay/faturalama durumu */
@Component({
  imports: [DatePipe, NgTemplateOutlet, RouterLink, ExpiryBadge, PhotoGallery],
  template: `
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <a routerLink="/vakalar" class="inline-flex items-center gap-1 text-sm font-medium text-muted hover:text-ink">
        <span class="icon text-[18px]">arrow_back</span> Vakalar
      </a>
      <span class="flex-1"></span>
      @if (c(); as doc) {
        @if (canApprove()) {
          <button class="btn btn-primary" [disabled]="busy()" (click)="approve()"><span class="icon">task_alt</span> Onayla</button>
        }
        @if (canTransfer()) {
          <button class="btn btn-secondary" (click)="transfer()"><span class="icon">swap_horiz</span> Devret</button>
        }
        @if (canEditPlan()) {
          <button class="btn btn-secondary" (click)="editPlan()"><span class="icon">edit_calendar</span> Planı düzenle</button>
        }
        @if (canCancel()) {
          <button class="btn btn-ghost text-danger" (click)="cancel()"><span class="icon">block</span> İptal et</button>
        }
        @if (doc.status === 'recorded' || doc.status === 'approved') {
          <button class="btn btn-secondary" (click)="sendEmail()"><span class="icon">forward_to_inbox</span> E-postayla gönder</button>
        }
        @if (doc.status !== 'planned') {
          <button class="btn btn-secondary" (click)="downloadPdf()"><span class="icon">picture_as_pdf</span> PDF indir</button>
        }
      }
    </div>

    @if (actionError()) {
      <p class="form-error mx-auto mb-4 max-w-4xl rounded-xl bg-danger-soft px-4 py-2.5" role="alert">{{ actionError() }}</p>
    }

    @if (notFound()) {
      <div class="card mx-auto max-w-lg px-6 py-12 text-center">
        <span class="icon text-[40px] text-muted">search_off</span>
        <p class="mt-2 font-medium">Vaka bulunamadı ya da görme yetkiniz yok.</p>
      </div>
    } @else if (loadError()) {
      <div class="card mx-auto max-w-lg px-6 py-12 text-center" role="alert">
        <span class="icon text-[40px] text-danger">error</span>
        <p class="mt-2 font-medium">Vaka yüklenemedi.</p>
        <button class="btn btn-secondary mt-4" (click)="load()">Yeniden dene</button>
      </div>
    } @else if (c(); as doc) {
      @if (doc.status === 'recorded' || doc.status === 'approved') {
        <div class="mx-auto mb-4 max-w-4xl">
          @if (doc.email_status === 'sent') {
            <p class="flex items-center gap-2 rounded-xl bg-ok-soft px-4 py-2.5 text-sm text-ok">
              <span class="icon text-[18px]">mark_email_read</span>
              Tutanak kopyası {{ doc.email_to }} adresine gönderildi · {{ doc.emailed_at | date: 'dd.MM.yyyy HH:mm' }}
            </p>
          } @else if (doc.email_status === 'failed') {
            <p class="flex items-center gap-2 rounded-xl bg-danger-soft px-4 py-2.5 text-sm text-danger">
              <span class="icon text-[18px]">error</span>
              {{ doc.email_to }} adresine e-posta gönderilemedi ({{ doc.emailed_at | date: 'dd.MM.yyyy HH:mm' }}) · {{ doc.email_error }}
            </p>
          } @else {
            <p class="flex items-center gap-2 rounded-xl bg-surface-2 px-4 py-2.5 text-sm text-muted">
              <span class="icon text-[18px]">unsubscribe</span> Tutanak kopyası henüz e-postayla gönderilmedi.
            </p>
          }
        </div>
      }

      <article class="card relative mx-auto max-w-4xl overflow-hidden p-6 sm:p-10">
        <header class="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-6">
          <div class="flex items-center gap-3">
            <div class="grid size-11 place-items-center rounded-xl bg-primary-soft text-primary-ink">
              <span class="icon">medical_services</span>
            </div>
            <div>
              <h1 class="font-mono text-xl font-semibold">{{ doc.number }}</h1>
              <div class="text-sm text-muted">{{ doc.procedure || 'Ameliyat türü belirtilmedi' }}</div>
            </div>
          </div>
          <div class="text-right">
            <div class="flex flex-wrap items-center justify-end gap-1.5">
              @if (doc.is_unplanned) {
                <span class="badge badge-warn"><span class="icon text-[13px]">bolt</span> Plansız (acil)</span>
              }
              @if (doc.is_overdue) {
                <span class="badge badge-danger"><span class="icon text-[13px]">schedule</span> Kaydı gecikti</span>
              }
              <span class="badge" [class]="badges[doc.status]">{{ labels[doc.status] }}</span>
            </div>
            <div class="mt-1.5 text-sm text-muted">Planlanan: {{ doc.scheduled_at | date: 'dd.MM.yyyy HH:mm' }}</div>
            @if (doc.performed_at) {
              <div class="text-sm text-muted">Kaydedilen: {{ doc.performed_at | date: 'dd.MM.yyyy HH:mm' }}</div>
            }
            @if (doc.approved_at) {
              <div class="text-sm text-muted">Onaylanan: {{ doc.approved_at | date: 'dd.MM.yyyy HH:mm' }}</div>
            }
          </div>
        </header>

        @if (doc.status === 'cancelled') {
          <div class="mt-6 rounded-xl bg-danger-soft p-4 text-sm text-danger">
            <strong>İptal:</strong> {{ doc.cancelled_at | date: 'dd.MM.yyyy HH:mm' }} · {{ doc.canceller?.name }} — {{ doc.cancel_reason }}
          </div>
        }

        <section class="mt-6 grid gap-6 sm:grid-cols-2">
          <div>
            <h2 class="doc-label">Hastane</h2>
            <div class="font-semibold">{{ doc.hospital?.name }}</div>
            <div class="text-sm text-muted">{{ address() }}</div>
            @if (doc.location) {
              <div class="mt-1 text-sm"><span class="text-muted">Bölüm:</span> {{ doc.location.path || doc.location.name }}</div>
            }
          </div>
          <div>
            <h2 class="doc-label">Doktor</h2>
            <div class="font-semibold">{{ doc.doctor?.display_name || 'Belirtilmedi' }}</div>
            @if (doc.doctor?.branch) {
              <div class="text-sm text-muted">{{ doc.doctor?.branch }}</div>
            }
          </div>
          <div>
            <h2 class="doc-label">Ameliyat</h2>
            <div class="font-semibold">{{ doc.procedure || '—' }}</div>
            <div class="text-sm"><span class="text-muted">Protokol no:</span> {{ doc.protocol_no || '—' }}</div>
          </div>
          <div>
            <h2 class="doc-label">Sorumlu personel</h2>
            <div class="font-semibold">{{ doc.user?.name }}</div>
            <div class="text-sm text-muted">
              Planlayan: {{ doc.creator?.name || '—' }}
              @if (doc.recorder) {
                · Kaydeden: {{ doc.recorder?.name }}
              }
            </div>
            @if (doc.approver) {
              <div class="text-sm text-muted">Onaylayan: {{ doc.approver?.name }}</div>
            }
          </div>
          @if (doc.signer_name) {
            <div>
              <h2 class="doc-label">Tutanağı imzalayan (hastane)</h2>
              <div class="font-semibold">{{ doc.signer_name }}</div>
              @if (doc.signer_title) {
                <div class="text-sm text-muted">{{ doc.signer_title }}</div>
              }
            </div>
          }
          @if (doc.performed_at) {
            <div>
              <h2 class="doc-label">Kayıt konumu</h2>
              @if (mapUrl(); as url) {
                <a [href]="url" target="_blank" rel="noopener" class="text-sm text-primary-ink hover:underline">
                  <span class="icon text-[14px] align-middle">location_on</span> İşlem konumunu haritada aç
                </a>
                @if (doc.location_accuracy) {
                  <div class="text-xs text-muted">Konum doğruluğu ±{{ doc.location_accuracy }} m</div>
                }
              } @else {
                <div class="inline-flex items-center gap-1 text-sm text-warn">
                  <span class="icon text-[16px]">location_off</span>
                  Konum alınamadı · {{ locationLabels[doc.location_error ?? 'unavailable'] }}
                </div>
              }
            </div>
          }
        </section>

        @if (doc.note) {
          <section class="mt-6">
            <h2 class="doc-label">Not</h2>
            <p class="whitespace-pre-line text-sm">{{ doc.note }}</p>
          </section>
        }

        <!-- Ürünler -->
        @if (doc.status === 'planned') {
          <section class="mt-8 rounded-xl border border-dashed border-line px-6 py-10 text-center">
            <span class="icon text-[36px] text-muted">qr_code_scanner</span>
            <p class="mt-2 font-medium text-ink">Ürünler mobil uygulamada ameliyat sırasında kaydedilir</p>
            <p class="mt-1 text-sm text-muted">Kayıttan sonra kullanılan ürünler ve imza burada görünür; vaka onay bekler.</p>
          </section>
        } @else {
          <section class="mt-8">
            <h2 class="doc-label">Kullanılan / Açılan (faturalanır)</h2>
            @if (billable().length) {
              <div class="table-wrap rounded-xl border border-line">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Ürün</th>
                      <th>Lot / Seri</th>
                      <th>SKT</th>
                      <th>Kaynak</th>
                      <th>Durum</th>
                      <th class="num">Adet</th>
                      <th>Fatura</th>
                    </tr>
                  </thead>
                  <tbody>
                    @for (i of billable(); track i.id) {
                      <ng-container *ngTemplateOutlet="row; context: { $implicit: i }" />
                    }
                  </tbody>
                </table>
              </div>
            } @else {
              <p class="rounded-xl bg-surface-2 px-4 py-3 text-sm text-muted">Faturalanacak ürün yok.</p>
            }
          </section>

          @if (unused().length) {
            <section class="mt-6">
              <h2 class="doc-label">Kullanılmayan (yalnız kayıt, faturalanmaz)</h2>
              <div class="table-wrap rounded-xl border border-line">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Ürün</th>
                      <th>Lot / Seri</th>
                      <th>SKT</th>
                      <th>Kaynak</th>
                      <th>Durum</th>
                      <th class="num">Adet</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    @for (i of unused(); track i.id) {
                      <ng-container *ngTemplateOutlet="row; context: { $implicit: i }" />
                    }
                  </tbody>
                </table>
              </div>
            </section>
          }
        }

        <!-- Kanıt -->
        @if (doc.has_signature) {
          <section class="mt-8">
            <h2 class="doc-label">İmza</h2>
            @if (signatureUrl(); as s) {
              <img [src]="s" alt="Tutanağı imzalayanın imzası" class="h-36 w-full max-w-md rounded-xl border border-line bg-white object-contain" />
            } @else {
              <div class="grid h-36 max-w-md place-items-center rounded-xl border border-dashed border-line text-sm text-muted">Yükleniyor…</div>
            }
            <div class="mt-1 max-w-md text-center text-xs text-muted">{{ doc.signer_name }}</div>
          </section>
        }

        @if (doc.status !== 'planned' || doc.photos?.length) {
          <section class="mt-8">
            <app-photo-gallery
              [photos]="doc.photos ?? []"
              [base]="'/cases/' + doc.id"
              [canAdd]="doc.status === 'recorded' || doc.status === 'approved'"
              (changed)="load()"
            />
          </section>
        }

        <!-- Geçmiş -->
        @if (doc.events?.length) {
          <section class="mt-8" aria-labelledby="case-history">
            <h2 class="doc-label" id="case-history">Geçmiş</h2>
            <ol class="relative ms-2 border-s border-line" data-testid="case-history">
              @for (ev of doc.events; track ev.id) {
                <li class="relative pb-4 ps-5 last:pb-0">
                  <span class="absolute -start-[5px] top-1.5 size-2.5 rounded-full bg-primary"></span>
                  <div class="text-sm font-medium text-ink">{{ ev.label }}</div>
                  <div class="text-xs text-muted">
                    {{ ev.created_at | date: 'dd.MM.yyyy HH:mm' : 'Europe/Istanbul' }}
                    @if (ev.user) {
                      · {{ ev.user.name }}
                    }
                  </div>
                  @if (eventDetail(ev); as d) {
                    <div class="mt-0.5 whitespace-pre-line text-sm text-muted">{{ d }}</div>
                  }
                </li>
              }
            </ol>
          </section>
        }
      </article>

      <ng-template #row let-i>
        <tr>
          <td>
            <div class="cell-title">{{ i.product?.name }}</div>
            <div class="cell-sub font-mono">{{ i.product?.code }}</div>
          </td>
          <td class="font-mono text-[13px]">
            @if (i.serial_number) {
              <div>SN {{ i.serial_number }}</div>
            }
            @if (i.lot_number) {
              <div [class.cell-sub]="i.serial_number">LOT {{ i.lot_number }}</div>
            }
            @if (!i.serial_number && !i.lot_number) {
              —
            }
          </td>
          <td>
            <app-expiry-badge [date]="i.expiry_date" [compact]="true" />
            @if (i.expired_at_use) {
              <div class="mt-1"><span class="badge badge-danger"><span class="icon text-[13px]">warning</span> SKT geçmiş ürün kullanıldı</span></div>
            }
          </td>
          <td class="text-sm">{{ source(i) }}</td>
          <td><span class="badge" [class]="i.status === 'unused' ? '' : 'badge-primary'">{{ itemLabels[i.status] }}</span></td>
          <td class="num font-semibold">{{ qty(i.quantity) }}<span class="ms-1 font-normal text-muted">{{ i.product?.unit }}</span></td>
          <td class="text-sm">
            @if (i.invoice_number) {
              <div class="font-mono text-ink">{{ i.invoice_number }}</div>
              <div class="cell-sub">{{ i.invoice_date | date: 'dd.MM.yyyy' }}</div>
            } @else if (i.billable) {
              <span class="text-muted">Bekliyor</span>
            }
          </td>
        </tr>
      </ng-template>
    } @else {
      <div class="card mx-auto h-96 max-w-4xl p-10"><div class="skeleton h-full"></div></div>
    }
  `,
})
export class CaseDetailPage implements OnDestroy {
  /** Rota parametresi :id */
  readonly id = input.required<string>();

  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);
  protected readonly qty = qty;
  protected readonly labels = CASE_STATUS_LABELS;
  protected readonly badges = CASE_STATUS_BADGES;
  protected readonly itemLabels: Record<string, string> = CASE_ITEM_LABELS;
  protected readonly locationLabels = LOCATION_ERROR_LABELS;

  protected readonly c = signal<SurgeryCase | null>(null);
  protected readonly notFound = signal(false);
  protected readonly loadError = signal(false);
  protected readonly busy = signal(false);
  protected readonly actionError = signal('');
  protected readonly signatureUrl = signal<string | null>(null);

  protected readonly billable = computed(() => (this.c()?.items ?? []).filter((i) => i.billable));
  protected readonly unused = computed(() => (this.c()?.items ?? []).filter((i) => !i.billable));
  private readonly anyInvoiced = computed(() => (this.c()?.items ?? []).some((i) => !!i.invoice_number));

  protected readonly canApprove = computed(() => this.c()?.status === 'recorded' && this.auth.can('cases.approve'));
  private readonly isOwner = computed(() => this.c()?.user?.id === this.auth.user()?.id);
  protected readonly canTransfer = computed(
    () => this.c()?.status === 'planned' && (this.auth.can('cases.approve') || this.isOwner()),
  );
  protected readonly canEditPlan = computed(
    () => this.c()?.status === 'planned' && (this.auth.can('cases.approve') || (this.isOwner() && this.auth.can('cases.create'))),
  );
  protected readonly canCancel = computed(() => {
    const s = this.c()?.status;
    if (s === 'planned') return this.auth.can('cases.approve') || (this.isOwner() && this.auth.can('cases.create'));
    if (s === 'recorded' || s === 'approved') return this.auth.can('cases.approve') && !this.anyInvoiced();
    return false;
  });

  /** Eski vakanın yanıtı yeni sayfayı ezmesin */
  private seq = 0;

  constructor() {
    effect(() => {
      this.id();
      this.c.set(null);
      this.load();
    });
  }

  ngOnDestroy(): void {
    this.seq++;
    this.revoke();
  }

  protected load(): void {
    const n = ++this.seq;
    const id = this.id();
    this.notFound.set(false);
    this.loadError.set(false);
    this.http.get<SurgeryCase>(`${API_URL}/cases/${id}`).subscribe({
      next: (d) => {
        if (n !== this.seq) return;
        this.c.set(d);
        this.revoke();
        // Görseller token'lı istekle alınır (img etiketi token gönderemez)
        if (d.has_signature) this.blob(`${API_URL}/cases/${d.id}/signature`, n, (u) => this.signatureUrl.set(u));
      },
      error: (e) => {
        if (n !== this.seq) return;
        if (e?.status === 404 || e?.status === 403) this.notFound.set(true);
        else this.loadError.set(true);
      },
    });
  }

  private blob(url: string, n: number, set: (u: string) => void): void {
    this.http.get(url, { responseType: 'blob' }).subscribe({
      next: (b) => {
        if (n !== this.seq) return;
        set(URL.createObjectURL(b));
      },
      error: () => {},
    });
  }

  private revoke(): void {
    const u = this.signatureUrl();
    if (u) URL.revokeObjectURL(u);
    this.signatureUrl.set(null);
  }

  protected source(i: SurgeryCaseItem): string {
    const w = i.warehouse;
    if (!w) return '—';
    return w.type === 'consignment' ? `Hastane: ${w.path || w.name}` : `Araç: ${w.name}`;
  }

  protected address(): string {
    const h = this.c()?.hospital;
    return h?.address || [h?.district, h?.city].filter(Boolean).join(', ');
  }

  protected mapUrl(): string | null {
    const d = this.c();
    return d?.latitude && d.longitude ? `https://www.google.com/maps?q=${d.latitude},${d.longitude}` : null;
  }

  protected approve(): void {
    const d = this.c();
    if (!d || this.busy()) return;
    confirm(this.dialog, {
      title: 'Vakayı onayla',
      message: `${d.number} onaylanınca kullanılan ve açılan ürünler faturalanacaklar listesine eklenir.`,
      confirmText: 'Onayla',
    }).subscribe((ok) => {
      if (!ok) return;
      this.busy.set(true);
      this.actionError.set('');
      this.http.post<{ message: string }>(`${API_URL}/cases/${d.id}/approve`, {}).subscribe({
        next: (r) => {
          this.toast.success(r.message);
          this.busy.set(false);
          this.load();
        },
        error: (e) => {
          this.actionError.set(errorMessage(e));
          this.busy.set(false);
          this.load();
        },
      });
    });
  }

  protected editPlan(): void {
    const d = this.c();
    if (!d) return;
    openModal(this.dialog, PlanCaseDialog, { surgeryCase: d }, 'lg').subscribe((saved) => saved && this.load());
  }

  protected transfer(): void {
    const d = this.c();
    if (!d) return;
    openModal<boolean>(this.dialog, TransferCaseDialog, d, 'md').subscribe((ok) => ok && this.load());
  }

  /** Olay türüne göre ek ayrıntı satırı */
  protected eventDetail(ev: CaseEvent): string {
    const d = ev.data;
    if (!d) return '';
    if (ev.type === 'transferred') return [`${d.from ?? '?'} → ${d.to ?? '?'}`, d.note].filter(Boolean).join(' · ');
    if (ev.type === 'cancelled') return d.reason ?? '';
    if (ev.type === 'photo_added' && d.count) return `${d.count} fotoğraf eklendi`;
    return '';
  }

  protected cancel(): void {
    const d = this.c();
    if (!d) return;
    openModal<boolean>(this.dialog, CancelCaseDialog, d, 'md').subscribe((ok) => ok && this.load());
  }

  protected sendEmail(): void {
    const d = this.c();
    if (!d) return;
    openModal<boolean>(this.dialog, EmailCaseDialog, d, 'md').subscribe(() => this.load());
  }

  /** Sunucuda üretilen PDF (e-postayla gidenle aynı) */
  protected downloadPdf(): void {
    const d = this.c();
    if (!d) return;
    this.http.get(`${API_URL}/cases/${d.id}/pdf`, { responseType: 'blob' }).subscribe({
      next: (b) => {
        const url = URL.createObjectURL(b);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${d.number}.pdf`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 10_000);
      },
      error: () => this.toast.error('PDF indirilemedi.'),
    });
  }
}
