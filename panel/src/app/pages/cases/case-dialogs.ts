import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { formatDate } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { ConsignmentLocation, Doctor, Hospital, Page, SurgeryCase, User } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { SearchSelect, SelectOption } from '../../ui/search-select';
import { ToastService } from '../../ui/toast';

export interface PlanCaseData {
  /** Düzenleme kipi */
  surgeryCase?: SurgeryCase;
  /** Hastane önceden seçili gelsin (hastane kartından) */
  hospitalId?: number;
}

/** Vaka planlama / plan düzenleme penceresi. Kapanınca kaydedilen vaka döner. */
@Component({
  imports: [FormsModule, ModalFrame, SearchSelect],
  template: `
    <app-modal [title]="editing ? 'Planı düzenle' : 'Vaka planla'" [subtitle]="data.surgeryCase?.number ?? 'Ürünler ameliyat sırasında mobil uygulamada kaydedilir.'">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="label" for="pc-hospital">Hastane *</label>
          <app-search-select
            inputId="pc-hospital"
            [options]="hospitalOptions()"
            [ngModel]="hospitalId()"
            (ngModelChange)="onHospital($event)"
            placeholder="Hastane seçin"
            searchPlaceholder="Hastane ara…"
          />
        </div>

        <div>
          <label class="label" for="pc-location">Bölüm / lokasyon</label>
          <select id="pc-location" class="select" [(ngModel)]="form.location_id" [disabled]="!hospitalId()">
            <option [ngValue]="null">— Belirtilmedi —</option>
            @for (l of locations(); track l.id) {
              <option [ngValue]="l.id">{{ l.path || l.name }}</option>
            }
          </select>
        </div>

        <div>
          <div class="flex items-center">
            <label class="label flex-1" for="pc-doctor">Doktor</label>
            @if (hospitalId() && !newDoctor()) {
              <button type="button" class="mb-1.5 text-xs font-medium text-primary-ink hover:underline" (click)="newDoctor.set(true)">+ Yeni doktor</button>
            }
          </div>
          <select id="pc-doctor" class="select" [(ngModel)]="form.doctor_id" [disabled]="!hospitalId()">
            <option [ngValue]="null">— Belirtilmedi —</option>
            @for (d of doctors(); track d.id) {
              <option [ngValue]="d.id">{{ d.display_name }}{{ d.branch ? ' · ' + d.branch : '' }}</option>
            }
          </select>
        </div>

        @if (newDoctor()) {
          <fieldset class="rounded-xl border border-line bg-surface-2 p-3.5 sm:col-span-2">
            <legend class="px-1 text-xs font-semibold text-muted">Yeni doktor</legend>
            <div class="grid gap-3 sm:grid-cols-3">
              <div>
                <label class="label" for="nd-name">Ad soyad *</label>
                <input id="nd-name" class="input" [(ngModel)]="doc.name" (keydown.enter)="$event.preventDefault(); saveDoctor()" />
              </div>
              <div>
                <label class="label" for="nd-title">Unvan</label>
                <input id="nd-title" class="input" [(ngModel)]="doc.title" placeholder="Prof. Dr." />
              </div>
              <div>
                <label class="label" for="nd-branch">Branş</label>
                <input id="nd-branch" class="input" [(ngModel)]="doc.branch" placeholder="Ortopedi" />
              </div>
            </div>
            @if (doctorError()) {
              <p class="form-error mt-2" role="alert">{{ doctorError() }}</p>
            }
            <div class="mt-3 flex gap-2">
              <button type="button" class="btn btn-primary btn-sm" [disabled]="doc.name.trim().length < 2 || savingDoctor()" (click)="saveDoctor()">Doktoru ekle</button>
              <button type="button" class="btn btn-ghost btn-sm" (click)="newDoctor.set(false)">Vazgeç</button>
            </div>
          </fieldset>
        }

        <div>
          <label class="label" for="pc-date">Tarih *</label>
          <input id="pc-date" class="input" type="date" [(ngModel)]="form.date" />
        </div>
        <div>
          <label class="label" for="pc-time">Saat *</label>
          <input id="pc-time" class="input" type="time" [(ngModel)]="form.time" />
        </div>

        <div>
          <label class="label" for="pc-procedure">Ameliyat türü</label>
          <input id="pc-procedure" class="input" [(ngModel)]="form.procedure" maxlength="200" placeholder="ör. Total diz protezi" />
        </div>
        <div>
          <label class="label" for="pc-protocol">Protokol no</label>
          <input id="pc-protocol" class="input" [(ngModel)]="form.protocol_no" maxlength="60" />
        </div>

        @if (canAssign() && users().length) {
          <div class="sm:col-span-2">
            <label class="label" for="pc-user">Sorumlu personel</label>
            <select id="pc-user" class="select" [(ngModel)]="form.user_id">
              @for (u of users(); track u.id) {
                <option [ngValue]="u.id">{{ u.name }}</option>
              }
            </select>
          </div>
        }

        <div class="sm:col-span-2">
          <label class="label" for="pc-note">Not</label>
          <textarea id="pc-note" class="textarea" rows="2" maxlength="1000" [(ngModel)]="form.note"></textarea>
        </div>

        @if (error()) {
          <p class="form-error sm:col-span-2" role="alert">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close()">Vazgeç</button>
        <button class="btn btn-primary" [disabled]="!valid() || saving()" (click)="save()">
          {{ saving() ? 'Kaydediliyor…' : editing ? 'Kaydet' : 'Planla' }}
        </button>
      </div>
    </app-modal>
  `,
})
export class PlanCaseDialog {
  protected readonly data = inject<PlanCaseData>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<SurgeryCase>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  private readonly auth = inject(AuthService);

  protected readonly editing = !!this.data.surgeryCase;
  protected readonly canAssign = computed(() => this.auth.can('cases.approve') && this.auth.can('users.view'));
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly hospitalId = signal<string | null>(null);
  protected readonly locations = signal<ConsignmentLocation[]>([]);
  protected readonly doctors = signal<Doctor[]>([]);
  protected readonly users = signal<User[]>([]);
  protected readonly hospitalOptions = computed<SelectOption[]>(() =>
    this.hospitals().map((h) => ({ value: String(h.id), label: h.city ? `${h.name} (${h.city})` : h.name })),
  );

  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly newDoctor = signal(false);
  protected readonly savingDoctor = signal(false);
  protected readonly doctorError = signal('');
  protected doc = { name: '', title: '', branch: '' };

  protected form = this.initialForm();

  constructor() {
    this.http
      .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { is_active: 1, per_page: 100 } })
      .subscribe({ next: (p) => this.hospitals.set(p.data), error: (e) => this.error.set(errorMessage(e)) });

    if (this.canAssign()) {
      this.http.get<Page<User>>(`${API_URL}/users`, { params: { is_active: 1, per_page: 100 } }).subscribe({
        next: (p) => this.users.set(p.data),
        error: () => this.users.set([]),
      });
    }

    const initial = this.data.surgeryCase?.hospital?.id ?? this.data.hospitalId;
    if (initial) {
      this.hospitalId.set(String(initial));
      this.loadHospital(initial);
    }
  }

  private initialForm() {
    const c = this.data.surgeryCase;
    if (c) {
      const when = new Date(c.scheduled_at);
      return {
        location_id: c.location?.id ?? (null as number | null),
        doctor_id: c.doctor?.id ?? (null as number | null),
        user_id: c.user?.id ?? (null as number | null),
        date: formatDate(when, 'yyyy-MM-dd', 'en-US'),
        time: formatDate(when, 'HH:mm', 'en-US'),
        procedure: c.procedure ?? '',
        protocol_no: c.protocol_no ?? '',
        note: c.note ?? '',
      };
    }
    const next = new Date(Date.now() + 3600_000);
    next.setMinutes(0, 0, 0);
    return {
      location_id: null as number | null,
      doctor_id: null as number | null,
      user_id: this.auth.user()?.id ?? (null as number | null),
      date: formatDate(next, 'yyyy-MM-dd', 'en-US'),
      time: formatDate(next, 'HH:mm', 'en-US'),
      procedure: '',
      protocol_no: '',
      note: '',
    };
  }

  /** Hastane değişince bölüm/doktor seçimleri sıfırlanır; listeler yeniden yüklenir. */
  protected onHospital(id: string | null): void {
    this.hospitalId.set(id);
    this.form.location_id = null;
    this.form.doctor_id = null;
    this.locations.set([]);
    this.doctors.set([]);
    this.newDoctor.set(false);
    if (id) this.loadHospital(Number(id));
  }

  private loadHospital(id: number): void {
    this.http.get<ConsignmentLocation[]>(`${API_URL}/hospitals/${id}/locations`).subscribe({
      next: (l) => {
        // Eski hastanenin yanıtı yeni seçimi ezmesin
        if (String(id) === this.hospitalId()) this.locations.set(l.filter((x) => x.is_active));
      },
      error: () => {},
    });
    this.http.get<Doctor[]>(`${API_URL}/hospitals/${id}/doctors`).subscribe({
      next: (d) => {
        if (String(id) !== this.hospitalId()) return;
        // Pasif doktor yalnız mevcut vakada seçiliyse görünür
        this.doctors.set(d.filter((x) => x.is_active || x.id === this.form.doctor_id));
      },
      error: () => {},
    });
  }

  protected valid(): boolean {
    return !!this.hospitalId() && !!this.form.date && !!this.form.time;
  }

  protected saveDoctor(): void {
    const hid = this.hospitalId();
    if (!hid || this.doc.name.trim().length < 2 || this.savingDoctor()) return;
    this.savingDoctor.set(true);
    this.doctorError.set('');
    const body = {
      name: this.doc.name.trim(),
      title: this.doc.title.trim() || null,
      branch: this.doc.branch.trim() || null,
    };
    this.http.post<Doctor>(`${API_URL}/hospitals/${hid}/doctors`, body).subscribe({
      next: (d) => {
        this.doctors.update((l) => [...l, d].sort((a, b) => a.name.localeCompare(b.name, 'tr')));
        this.form.doctor_id = d.id;
        this.doc = { name: '', title: '', branch: '' };
        this.newDoctor.set(false);
        this.savingDoctor.set(false);
        this.toast.success('Doktor eklendi.');
      },
      error: (e) => {
        this.doctorError.set(errorMessage(e));
        this.savingDoctor.set(false);
      },
    });
  }

  protected save(): void {
    if (!this.valid() || this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    const body: Record<string, unknown> = {
      hospital_id: Number(this.hospitalId()),
      scheduled_at: `${this.form.date} ${this.form.time}`,
      location_id: this.form.location_id,
      doctor_id: this.form.doctor_id,
      procedure: this.form.procedure.trim() || null,
      protocol_no: this.form.protocol_no.trim() || null,
      note: this.form.note.trim() || null,
    };
    if (this.canAssign() && this.form.user_id) body['user_id'] = this.form.user_id;

    const c = this.data.surgeryCase;
    const req = c
      ? this.http.put<{ message: string; case: SurgeryCase }>(`${API_URL}/cases/${c.id}`, body)
      : this.http.post<{ message: string; case: SurgeryCase }>(`${API_URL}/cases`, body);
    req.subscribe({
      next: (r) => {
        this.toast.success(r.message);
        this.ref.close(r.case);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}

/** Vaka iptal gerekçesi */
@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal title="Vakayı iptal et" [subtitle]="data.number">
      <div class="space-y-3">
        @if (data.status !== 'planned') {
          <div class="flex gap-3 rounded-xl bg-warn-soft p-3.5 text-sm text-warn">
            <span class="icon">info</span>
            <p>Vakada düşülen ürünler geldikleri yere (hastane rafı ya da araç) geri konur. İptal kaydı ayrıca saklanır; vaka silinmez.</p>
          </div>
        }
        <div>
          <label class="label" for="cc-reason">İptal gerekçesi *</label>
          <textarea id="cc-reason" class="textarea" rows="3" [(ngModel)]="reason" placeholder="ör. Ameliyat ertelendi"></textarea>
        </div>
        @if (error()) {
          <p class="form-error" role="alert">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-danger" [disabled]="reason.trim().length < 3 || saving()" (click)="save()">İptal et</button>
      </div>
    </app-modal>
  `,
})
export class CancelCaseDialog {
  protected readonly data = inject<SurgeryCase>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected reason = '';
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected save(): void {
    if (this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    this.http.post<{ message: string }>(`${API_URL}/cases/${this.data.id}/cancel`, { reason: this.reason.trim() }).subscribe({
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

/** Vaka tutanağını e-postayla gönderme */
@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal title="Tutanağı e-postayla gönder" [subtitle]="data.number">
      <div class="space-y-3">
        <p class="text-sm text-muted">Vaka kullanım tutanağının PDF kopyası aşağıdaki adrese gönderilir.</p>
        <div>
          <label class="label" for="ce-email">E-posta *</label>
          <input id="ce-email" type="email" class="input" [(ngModel)]="email" (keydown.enter)="send()" />
        </div>
        @if (error()) {
          <p class="form-error" role="alert">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-primary" [disabled]="!valid() || saving()" (click)="send()">
          <span class="icon">send</span> {{ saving() ? 'Gönderiliyor…' : 'Gönder' }}
        </button>
      </div>
    </app-modal>
  `,
})
export class EmailCaseDialog {
  protected readonly data = inject<SurgeryCase>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected email = this.data.email_to ?? this.data.contact?.email ?? '';
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected valid(): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email.trim());
  }

  protected send(): void {
    if (!this.valid() || this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    this.http.post<{ message: string }>(`${API_URL}/cases/${this.data.id}/email`, { email: this.email.trim() }).subscribe({
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
