import { DIALOG_DATA, Dialog, DialogRef } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CASE_STATUS_BADGES, CASE_STATUS_LABELS, Doctor, Page, SurgeryCase } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';

// =============================================================== Doktorlar

@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal [title]="data.doctor ? 'Doktoru düzenle' : 'Doktor ekle'" subtitle="Vakalarda seçilen hastane doktoru">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="label" for="dr-name">Ad soyad *</label>
          <input id="dr-name" class="input" [(ngModel)]="form.name" />
        </div>
        <div>
          <label class="label" for="dr-title">Unvan</label>
          <input id="dr-title" class="input" [(ngModel)]="form.title" placeholder="Prof. Dr." maxlength="40" />
        </div>
        <div>
          <label class="label" for="dr-branch">Branş</label>
          <input id="dr-branch" class="input" [(ngModel)]="form.branch" placeholder="Ortopedi" />
        </div>
        <div>
          <label class="label" for="dr-phone">Telefon</label>
          <input id="dr-phone" class="input" [(ngModel)]="form.phone" />
        </div>
        <div>
          <label class="label" for="dr-email">E-posta</label>
          <input id="dr-email" class="input" type="email" [(ngModel)]="form.email" />
        </div>
        @if (data.doctor && data.canEdit) {
          <label class="check sm:col-span-2">
            <input type="checkbox" class="switch" [(ngModel)]="form.is_active" /> Aktif
          </label>
        }
        @if (error()) {
          <p class="form-error sm:col-span-2" role="alert">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-primary" [disabled]="form.name.trim().length < 2 || saving()" (click)="save()">Kaydet</button>
      </div>
    </app-modal>
  `,
})
export class DoctorDialog {
  protected readonly data = inject<{ hospitalId: number; doctor?: Doctor; canEdit: boolean }>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected form = {
    name: this.data.doctor?.name ?? '',
    title: this.data.doctor?.title ?? '',
    branch: this.data.doctor?.branch ?? '',
    phone: this.data.doctor?.phone ?? '',
    email: this.data.doctor?.email ?? '',
    is_active: this.data.doctor?.is_active ?? true,
  };

  protected save(): void {
    if (this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    const body: Record<string, unknown> = {
      name: this.form.name.trim(),
      title: this.form.title.trim() || null,
      branch: this.form.branch.trim() || null,
      phone: this.form.phone.trim() || null,
      email: this.form.email.trim() || null,
    };
    const base = `${API_URL}/hospitals/${this.data.hospitalId}/doctors`;
    if (this.data.doctor && this.data.canEdit) body['is_active'] = this.form.is_active;
    const req = this.data.doctor ? this.http.put(`${base}/${this.data.doctor.id}`, body) : this.http.post(base, body);
    req.subscribe({
      next: () => {
        this.toast.success('Doktor kaydedildi.');
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
  selector: 'app-hospital-doctors',
  template: `
    <div class="card">
      <div class="card-head">
        <div class="flex-1">
          <h2 class="card-title">Doktorlar</h2>
          <p class="text-xs text-muted">Vaka planlanırken seçilen hastane doktorları.</p>
        </div>
        @if (canAdd()) {
          <button class="btn btn-primary btn-sm" (click)="open()"><span class="icon">person_add</span> Doktor ekle</button>
        }
      </div>
      @if (loadError()) {
        <div class="flex items-center gap-3 border-b border-line bg-danger-soft px-5 py-3 text-sm text-danger" role="alert">
          <span class="icon">error</span><span class="flex-1">Doktorlar yüklenemedi.</span>
          <button class="btn btn-secondary btn-sm" (click)="load()">Yeniden dene</button>
        </div>
      }
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Doktor</th>
              <th>Branş</th>
              <th>Telefon</th>
              <th>E-posta</th>
              <th class="num">Vaka</th>
              <th>Durum</th>
              @if (canEdit()) {
                <th class="actions"><span class="sr-only">İşlemler</span></th>
              }
            </tr>
          </thead>
          <tbody>
            @for (d of doctors(); track d.id) {
              <tr [class.opacity-60]="!d.is_active">
                <td class="cell-title">{{ d.display_name }}</td>
                <td>{{ d.branch || '—' }}</td>
                <td>{{ d.phone || '—' }}</td>
                <td>{{ d.email || '—' }}</td>
                <td class="num">{{ d.cases_count ?? 0 }}</td>
                <td>
                  @if (canEdit()) {
                    <input type="checkbox" class="switch" [checked]="d.is_active" (change)="toggle(d)" [attr.aria-label]="d.display_name + ' aktif'" />
                  } @else {
                    <span class="badge" [class]="d.is_active ? 'badge-ok' : ''">{{ d.is_active ? 'Aktif' : 'Pasif' }}</span>
                  }
                </td>
                @if (canEdit()) {
                  <td class="actions">
                    <button class="btn-icon" (click)="open(d)" aria-label="Düzenle" title="Düzenle"><span class="icon">edit</span></button>
                  </td>
                }
              </tr>
            } @empty {
              <tr>
                <td [attr.colspan]="canEdit() ? 7 : 6" class="py-12 text-center text-sm text-muted">
                  {{ loaded() ? 'Henüz doktor yok. Vaka planlarken de eklenebilir.' : 'Yükleniyor…' }}
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    </div>
  `,
})
export class HospitalDoctorsTab {
  readonly hospitalId = input.required<number>();
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly auth = inject(AuthService);
  protected readonly doctors = signal<Doctor[]>([]);
  protected readonly loaded = signal(false);
  protected readonly loadError = signal(false);
  protected readonly canEdit = computed(() => this.auth.can('hospitals.update'));
  protected readonly canAdd = computed(() => this.auth.can(['hospitals.update', 'cases.create']));
  /** Hastane değişince eski yanıt yenisini ezmesin */
  private seq = 0;

  constructor() {
    effect(() => {
      this.hospitalId();
      this.doctors.set([]);
      this.loaded.set(false);
      this.load();
    });
  }

  protected load(): void {
    const n = ++this.seq;
    this.loadError.set(false);
    this.http.get<Doctor[]>(`${API_URL}/hospitals/${this.hospitalId()}/doctors`).subscribe({
      next: (d) => {
        if (n !== this.seq) return;
        this.doctors.set(d);
        this.loaded.set(true);
      },
      error: () => {
        if (n !== this.seq) return;
        this.loadError.set(true);
        this.loaded.set(true);
      },
    });
  }

  protected open(doctor?: Doctor): void {
    openModal<boolean>(this.dialog, DoctorDialog, { hospitalId: this.hospitalId(), doctor, canEdit: this.canEdit() }, 'md').subscribe(
      (ok) => ok && this.load(),
    );
  }

  protected toggle(d: Doctor): void {
    this.http.put(`${API_URL}/hospitals/${this.hospitalId()}/doctors/${d.id}`, { is_active: !d.is_active }).subscribe({
      next: () => {
        this.toast.success(d.is_active ? 'Doktor pasife alındı.' : 'Doktor aktifleştirildi.');
        this.load();
      },
      error: (e) => {
        this.toast.error(errorMessage(e));
        this.load();
      },
    });
  }
}

// =============================================================== Vakalar

@Component({
  selector: 'app-hospital-cases',
  imports: [DatePipe, RouterLink],
  template: `
    <div class="card">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Vaka</th>
              <th>Tarih</th>
              <th>Doktor</th>
              <th>Ameliyat</th>
              <th>Personel</th>
              <th>Durum</th>
            </tr>
          </thead>
          <tbody>
            @for (c of page()?.data ?? []; track c.id) {
              <tr>
                <td><a class="font-mono font-medium text-ink hover:underline" [routerLink]="['/vakalar', c.id]">{{ c.number }}</a></td>
                <td class="whitespace-nowrap">{{ c.scheduled_at | date: 'dd.MM.yyyy HH:mm' }}</td>
                <td>{{ c.doctor?.display_name || '—' }}</td>
                <td>{{ c.procedure || '—' }}</td>
                <td>{{ c.user?.name }}</td>
                <td>
                  @if (c.is_overdue) {
                    <span class="badge badge-danger">Kaydı gecikti</span>
                  } @else {
                    <span class="badge" [class]="badges[c.status]">{{ labels[c.status] }}</span>
                  }
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="6" class="py-14 text-center text-muted">
                  {{ failed() ? 'Vakalar yüklenemedi.' : page() ? 'Bu hastaneye ait vaka yok.' : 'Yükleniyor…' }}
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if ((page()?.total ?? 0) > (page()?.data?.length ?? 0)) {
        <div class="border-t border-line p-3 text-center">
          <a class="btn btn-ghost btn-sm" routerLink="/vakalar">Tüm vakalar ({{ page()?.total }})</a>
        </div>
      }
    </div>
  `,
})
export class HospitalCasesTab {
  readonly hospitalId = input.required<number>();
  private readonly http = inject(HttpClient);
  protected readonly labels = CASE_STATUS_LABELS;
  protected readonly badges = CASE_STATUS_BADGES;
  protected readonly page = signal<Page<SurgeryCase> | null>(null);
  protected readonly failed = signal(false);
  private seq = 0;

  constructor() {
    effect(() => {
      const id = this.hospitalId();
      const n = ++this.seq;
      this.page.set(null);
      this.failed.set(false);
      this.http.get<Page<SurgeryCase>>(`${API_URL}/cases`, { params: { hospital_id: id, per_page: 50 } }).subscribe({
        next: (p) => n === this.seq && this.page.set(p),
        error: () => n === this.seq && this.failed.set(true),
      });
    });
  }
}
