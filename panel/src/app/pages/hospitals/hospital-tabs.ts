import { DIALOG_DATA, Dialog, DialogRef } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage, qty } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { ConsignmentLocation, DELIVERY_LABELS, Delivery, HospitalContact, Page, Stock } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { ExpiryBadge } from '../../ui/expiry-badge';
import { ModalFrame } from '../../ui/modal-frame';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';

// =============================================================== Konsinye stok

interface ConsignmentResponse {
  summary: { units: number; products: number; expiring: number; expired: number };
  rows: Stock[];
}

@Component({
  selector: 'app-hospital-consignment',
  imports: [ExpiryBadge, RouterLink],
  template: `
    @if (data(); as d) {
      <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <div class="card p-4">
          <div class="text-xs text-muted">Konsinye ürün</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ qty(d.summary.units) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-muted">Farklı ürün</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ d.summary.products }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-muted">SKT'si 90 gün içinde dolan</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums" [class.text-warn]="d.summary.expiring">{{ qty(d.summary.expiring) }}</div>
        </div>
        <div class="card p-4">
          <div class="text-xs text-muted">SKT'si geçmiş</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums" [class.text-danger]="d.summary.expired">{{ qty(d.summary.expired) }}</div>
        </div>
      </div>

      @if (locations().length > 1) {
        <div class="mb-3 flex flex-wrap gap-2">
          <button class="chip" [class.chip-on]="!locationId()" (click)="locationId.set(null)">Tüm lokasyonlar</button>
          @for (l of locations(); track l.id) {
            <button class="chip" [class.chip-on]="locationId() === l.id" (click)="locationId.set(l.id)">{{ l.name }}</button>
          }
        </div>
      }

      <div class="card">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Ürün</th>
                <th>Lokasyon</th>
                <th>Lot / Seri</th>
                <th>SKT</th>
                <th class="num">Adet</th>
              </tr>
            </thead>
            <tbody>
              @for (s of visible(); track s.id) {
                <tr>
                  <td>
                    <div class="cell-title">{{ s.product?.name }}</div>
                    <div class="cell-sub font-mono">{{ s.product?.code }}</div>
                  </td>
                  <td>{{ s.warehouse?.name }}</td>
                  <td class="font-mono text-[13px]">
                    @if (s.serial_number) {
                      <div>SN {{ s.serial_number }}</div>
                    }
                    @if (s.lot_number) {
                      <div [class.cell-sub]="s.serial_number">LOT {{ s.lot_number }}</div>
                    }
                  </td>
                  <td><app-expiry-badge [date]="s.expiry_date" /></td>
                  <td class="num font-semibold text-ink">{{ qty(s.quantity) }}<span class="ms-1 font-normal text-muted">{{ s.product?.unit }}</span></td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="5" class="py-14 text-center">
                    <span class="icon text-[40px] text-muted">inventory</span>
                    <p class="mt-2 font-medium text-ink">Bu hastanede konsinye ürün yok</p>
                    @if (auth.can('deliveries.create')) {
                      <a class="btn btn-primary btn-sm mt-3" routerLink="/teslimat" [queryParams]="{ hastane: hospitalId() }">Teslimat yap</a>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </div>
    } @else {
      <div class="card h-64 p-5"><div class="skeleton h-full"></div></div>
    }
  `,
})
export class HospitalConsignmentTab {
  readonly hospitalId = input.required<number>();
  readonly locations = input<ConsignmentLocation[]>([]);
  private readonly http = inject(HttpClient);
  protected readonly auth = inject(AuthService);
  protected readonly qty = qty;
  protected readonly data = signal<ConsignmentResponse | null>(null);
  protected readonly locationId = signal<number | null>(null);
  protected readonly visible = computed(() => {
    const id = this.locationId();
    const rows = this.data()?.rows ?? [];
    return id ? rows.filter((r) => r.warehouse_id === id) : rows;
  });

  constructor() {
    effect(() => {
      const id = this.hospitalId();
      this.http.get<ConsignmentResponse>(`${API_URL}/hospitals/${id}/consignment`).subscribe((d) => this.data.set(d));
    });
  }
}

// =============================================================== Teslimatlar

@Component({
  selector: 'app-hospital-deliveries',
  imports: [DatePipe, RouterLink],
  template: `
    <div class="card">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Belge</th>
              <th>Tarih</th>
              <th>Lokasyon</th>
              <th>Teslim alan / eden</th>
              <th>Personel</th>
              <th class="num">Ürün</th>
            </tr>
          </thead>
          <tbody>
            @for (d of page()?.data ?? []; track d.id) {
              <tr>
                <td>
                  <a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/teslimatlar', d.id]">{{ d.number }}</a>
                  <div class="mt-1 flex gap-1">
                    <span class="badge" [class]="d.type === 'return' ? 'badge-warn' : 'badge-primary'">{{ labels[d.type] }}</span>
                    @if (d.status === 'cancelled') {
                      <span class="badge badge-danger">İptal</span>
                    }
                  </div>
                </td>
                <td class="whitespace-nowrap">{{ d.delivered_at | date: 'dd.MM.yyyy HH:mm' }}</td>
                <td>{{ d.location?.name }}</td>
                <td>{{ d.receiver_name }}</td>
                <td>{{ d.user?.name }}</td>
                <td class="num">{{ qty(d.units) }} <span class="cell-sub block">{{ d.lines_count }} kalem</span></td>
              </tr>
            } @empty {
              <tr>
                <td colspan="6" class="py-14 text-center text-muted">{{ page() ? 'Bu hastaneye ait belge yok.' : 'Yükleniyor…' }}</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if ((page()?.total ?? 0) > (page()?.data?.length ?? 0)) {
        <div class="border-t border-line p-3 text-center">
          <a class="btn btn-ghost btn-sm" routerLink="/teslimatlar">Tüm belgeler ({{ page()?.total }})</a>
        </div>
      }
    </div>
  `,
})
export class HospitalDeliveriesTab {
  readonly hospitalId = input.required<number>();
  private readonly http = inject(HttpClient);
  protected readonly qty = qty;
  protected readonly labels: Record<string, string> = DELIVERY_LABELS;
  protected readonly page = signal<Page<Delivery> | null>(null);

  constructor() {
    effect(() => {
      const id = this.hospitalId();
      this.http
        .get<Page<Delivery>>(`${API_URL}/deliveries`, { params: { hospital_id: id, per_page: 50 } })
        .subscribe((p) => this.page.set(p));
    });
  }
}

// =============================================================== Hastane personeli

@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal [title]="data.contact ? 'Kişiyi düzenle' : 'Hastane personeli ekle'" subtitle="Hastanede teslim alan / muhatap olduğunuz kişi">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label" for="hc-name">Ad soyad *</label>
          <input id="hc-name" class="input" [(ngModel)]="form.name" />
        </div>
        <div>
          <label class="label" for="hc-title">Görevi</label>
          <input id="hc-title" class="input" [(ngModel)]="form.title" placeholder="ör. Sorumlu hemşire, satın alma" />
        </div>
        <div>
          <label class="label" for="hc-phone">Telefon</label>
          <input id="hc-phone" class="input" [(ngModel)]="form.phone" />
        </div>
        <div>
          <label class="label" for="hc-email">E-posta</label>
          <input id="hc-email" class="input" type="email" [(ngModel)]="form.email" />
        </div>
        @if (data.locations.length > 1) {
          <div class="sm:col-span-2">
            <label class="label" for="hc-loc">Bağlı lokasyon</label>
            <select id="hc-loc" class="select" [(ngModel)]="form.location_id">
              <option [ngValue]="null">— Tüm lokasyonlar —</option>
              @for (l of data.locations; track l.id) {
                <option [ngValue]="l.id">{{ l.name }}</option>
              }
            </select>
            <p class="hint">Teslimatta bu lokasyon seçilince önce bu kişi önerilir.</p>
          </div>
        }
        <label class="check sm:col-span-2">
          <input type="checkbox" class="switch" [(ngModel)]="form.is_active" /> Aktif
        </label>
        @if (error()) {
          <p class="form-error sm:col-span-2">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-primary" [disabled]="!form.name.trim() || saving()" (click)="save()">Kaydet</button>
      </div>
    </app-modal>
  `,
})
export class ContactDialog {
  protected readonly data = inject<{ hospitalId: number; contact?: HospitalContact; locations: ConsignmentLocation[] }>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected form = {
    name: this.data.contact?.name ?? '',
    title: this.data.contact?.title ?? '',
    phone: this.data.contact?.phone ?? '',
    email: this.data.contact?.email ?? '',
    location_id: this.data.contact?.location_id ?? null,
    is_active: this.data.contact?.is_active ?? true,
  };

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const body = {
      ...this.form,
      title: this.form.title || null,
      phone: this.form.phone || null,
      email: this.form.email || null,
    };
    const base = `${API_URL}/hospitals/${this.data.hospitalId}/contacts`;
    const req = this.data.contact ? this.http.put(`${base}/${this.data.contact.id}`, body) : this.http.post(base, body);
    req.subscribe({
      next: () => {
        this.toast.success('Kişi kaydedildi.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}

@Component({
  selector: 'app-hospital-contacts',
  template: `
    <div class="card">
      <div class="card-head">
        <div class="flex-1">
          <h2 class="card-title">Hastane personeli</h2>
          <p class="text-xs text-muted">Teslimatta "teslim alan" olarak seçilen kişiler. Firma personeli değildir.</p>
        </div>
        @if (canEdit()) {
          <button class="btn btn-primary btn-sm" (click)="open()"><span class="icon">person_add</span> Kişi ekle</button>
        }
      </div>
      <ul class="divide-y divide-line">
        @for (c of contacts(); track c.id) {
          <li class="flex flex-wrap items-center gap-3 px-5 py-3" [class.opacity-60]="!c.is_active">
            <div class="grid size-9 place-items-center rounded-full bg-surface-2 text-xs font-semibold text-ink-2">{{ initials(c.name) }}</div>
            <div class="min-w-48 flex-1">
              <div class="text-sm font-medium text-ink">
                {{ c.name }}
                @if (!c.is_active) {
                  <span class="badge ms-1">Pasif</span>
                }
              </div>
              <div class="text-xs text-muted">
                {{ c.title || 'Görevi belirtilmemiş' }}{{ c.location ? ' · ' + c.location.name : '' }}
              </div>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
              @if (c.phone) {
                <a [href]="'tel:' + c.phone" class="inline-flex items-center gap-1 text-primary-ink hover:underline"><span class="icon text-[16px]">call</span>{{ c.phone }}</a>
              }
              @if (c.email) {
                <a [href]="'mailto:' + c.email" class="inline-flex items-center gap-1 text-primary-ink hover:underline"><span class="icon text-[16px]">mail</span>{{ c.email }}</a>
              }
            </div>
            @if (canEdit()) {
              <button class="btn-icon" (click)="open(c)" aria-label="Düzenle" title="Düzenle"><span class="icon">edit</span></button>
              <button class="btn-icon danger" (click)="remove(c)" aria-label="Sil" title="Sil"><span class="icon">delete</span></button>
            }
          </li>
        } @empty {
          <li class="px-5 py-12 text-center text-sm text-muted">Henüz kayıtlı kişi yok. Teslimat sırasında da eklenebilir.</li>
        }
      </ul>
    </div>
  `,
})
export class HospitalContactsTab {
  readonly hospitalId = input.required<number>();
  readonly locations = input<ConsignmentLocation[]>([]);
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly auth = inject(AuthService);
  protected readonly contacts = signal<HospitalContact[]>([]);
  protected readonly canEdit = computed(() => this.auth.can('hospitals.update'));

  constructor() {
    effect(() => {
      this.hospitalId();
      this.load();
    });
  }

  private load(): void {
    this.http
      .get<HospitalContact[]>(`${API_URL}/hospitals/${this.hospitalId()}/contacts`, { params: { include_inactive: 1 } })
      .subscribe((c) => this.contacts.set(c));
  }

  protected initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]!.toLocaleUpperCase('tr')).join('');
  }

  protected open(contact?: HospitalContact): void {
    openModal<boolean>(this.dialog, ContactDialog, { hospitalId: this.hospitalId(), contact, locations: this.locations() }, 'md').subscribe(
      (ok) => ok && this.load(),
    );
  }

  protected remove(c: HospitalContact): void {
    confirm(this.dialog, {
      title: 'Kişiyi sil',
      message: `${c.name} listeden silinsin mi? Geçmiş belgelerde adı kalır.`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/hospitals/${this.hospitalId()}/contacts/${c.id}`).subscribe({
        next: () => {
          this.toast.success('Kişi silindi.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }
}

// =============================================================== Lokasyonlar

@Component({
  selector: 'app-hospital-locations',
  imports: [FormsModule],
  template: `
    <div class="card">
      <div class="card-head">
        <div class="flex-1">
          <h2 class="card-title">Konsinye lokasyonları</h2>
          <p class="text-xs text-muted">Ürünlerin hastanede durduğu yerler. Sayım ve teslimat lokasyon bazında yapılır.</p>
        </div>
      </div>
      <ul class="divide-y divide-line">
        @for (l of locations(); track l.id) {
          <li class="flex flex-wrap items-center gap-3 px-5 py-3" [class.opacity-60]="!l.is_active">
            <div class="grid size-9 place-items-center rounded-lg bg-primary-soft text-primary-ink"><span class="icon text-[18px]">shelves</span></div>
            <div class="min-w-48 flex-1">
              @if (editingId() === l.id) {
                <input class="input h-8" [(ngModel)]="editName" (keydown.enter)="rename(l)" (keydown.escape)="editingId.set(null)" aria-label="Lokasyon adı" />
              } @else {
                <div class="text-sm font-medium text-ink">
                  {{ l.name }}
                  @if (!l.is_active) {
                    <span class="badge ms-1">Pasif</span>
                  }
                </div>
              }
              <div class="font-mono text-xs text-muted">{{ l.code }} · {{ qty(l.units) }} ürün</div>
            </div>
            @if (canEdit()) {
              @if (editingId() === l.id) {
                <button class="btn btn-primary btn-sm" (click)="rename(l)">Kaydet</button>
                <button class="btn btn-ghost btn-sm" (click)="editingId.set(null)">Vazgeç</button>
              } @else {
                <button class="btn-icon" (click)="startEdit(l)" aria-label="Adını değiştir" title="Adını değiştir"><span class="icon">edit</span></button>
                <button class="btn btn-ghost btn-sm" (click)="toggle(l)">{{ l.is_active ? 'Pasife al' : 'Aktifleştir' }}</button>
              }
            }
          </li>
        }
      </ul>
      @if (canEdit()) {
        <div class="flex flex-wrap gap-2 border-t border-line p-4">
          <input class="input max-w-xs" [(ngModel)]="newName" placeholder="ör. Ameliyathane dolabı" aria-label="Yeni lokasyon adı" (keydown.enter)="add()" />
          <button class="btn btn-secondary" [disabled]="!newName.trim()" (click)="add()"><span class="icon">add</span> Lokasyon ekle</button>
        </div>
      }
    </div>
  `,
})
export class HospitalLocationsTab {
  readonly hospitalId = input.required<number>();
  readonly locations = input<ConsignmentLocation[]>([]);
  /** Değişiklik olunca üst bileşen listeyi yeniler */
  readonly changed = input<() => void>(() => {});
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  private readonly auth = inject(AuthService);
  protected readonly qty = qty;
  protected readonly canEdit = computed(() => this.auth.can('hospitals.update'));
  protected readonly editingId = signal<number | null>(null);
  protected editName = '';
  protected newName = '';

  private base(): string {
    return `${API_URL}/hospitals/${this.hospitalId()}/locations`;
  }

  private done(message: string): void {
    this.toast.success(message);
    this.changed()();
  }

  protected add(): void {
    const name = this.newName.trim();
    if (!name) return;
    this.http.post(this.base(), { name }).subscribe({
      next: () => {
        this.newName = '';
        this.done('Lokasyon eklendi.');
      },
      error: (e) => this.toast.error(errorMessage(e)),
    });
  }

  protected startEdit(l: ConsignmentLocation): void {
    this.editName = l.name;
    this.editingId.set(l.id);
  }

  protected rename(l: ConsignmentLocation): void {
    this.http.put(`${this.base()}/${l.id}`, { name: this.editName.trim() }).subscribe({
      next: () => {
        this.editingId.set(null);
        this.done('Lokasyon güncellendi.');
      },
      error: (e) => this.toast.error(errorMessage(e)),
    });
  }

  protected toggle(l: ConsignmentLocation): void {
    this.http.put(`${this.base()}/${l.id}`, { is_active: !l.is_active }).subscribe({
      next: () => this.done(l.is_active ? 'Lokasyon pasife alındı.' : 'Lokasyon aktifleştirildi.'),
      error: (e) => this.toast.error(errorMessage(e)),
    });
  }
}
