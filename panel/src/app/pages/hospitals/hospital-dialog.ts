import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, effect, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { HOSPITAL_LABELS, HOSPITAL_TYPES, Hospital, HospitalType, Page, User } from '../../core/models';
import { ProvinceService } from '../../core/province.service';
import { SearchSelect, SelectOption } from '../../ui/search-select';
import { ModalFrame } from '../../ui/modal-frame';
import { ToastService } from '../../ui/toast';

@Component({
  imports: [ReactiveFormsModule, ModalFrame, SearchSelect],
  template: `
    <form [formGroup]="form" (ngSubmit)="save()">
      <app-modal [title]="data.hospital ? 'Hastaneyi düzenle' : 'Yeni hastane'">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="label" for="h-name">Hastane adı *</label>
            <input id="h-name" class="input" formControlName="name" />
          </div>
          <div>
            <label class="label" for="h-type">Tür</label>
            <select id="h-type" class="select" formControlName="type">
              @for (t of types; track t) {
                <option [value]="t">{{ labels[t] }}</option>
              }
            </select>
          </div>
          <div>
            <label class="label" for="h-code">Kod</label>
            <input id="h-code" class="input font-mono" formControlName="code" placeholder="ör. HST-11" />
          </div>
          <div>
            <label class="label" for="h-city">İl</label>
            <app-search-select
              inputId="h-city"
              formControlName="city"
              [options]="provinces()"
              placeholder="İl seçin"
              searchPlaceholder="İl ara veya plaka kodu yaz"
              [clearable]="true"
            />
          </div>
          <div>
            <label class="label" for="h-district">İlçe</label>
            <app-search-select
              inputId="h-district"
              formControlName="district"
              [options]="districts()"
              [placeholder]="city() ? (districtsLoading() ? 'Yükleniyor…' : 'İlçe seçin') : 'Önce il seçin'"
              searchPlaceholder="İlçe ara"
              [clearable]="true"
            />
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="h-address">Adres</label>
            <input id="h-address" class="input" formControlName="address" />
          </div>
          @if (canPickResponsible) {
            <div class="sm:col-span-2">
              <label class="label" for="h-resp">Sorumlu mümessil (firma personeli)</label>
              <app-search-select
                inputId="h-resp"
                formControlName="responsible_user_id"
                [options]="staff()"
                placeholder="Atanmamış"
                searchPlaceholder="Personel ara"
                [clearable]="true"
              />
              <p class="hint">Bu hastaneye bakan kişi. Teslimat ekranında hastane onun listesinde önce çıkar.</p>
            </div>
          }
          <div class="sm:col-span-2">
            <label class="label" for="h-notes">Notlar</label>
            <textarea id="h-notes" class="textarea" rows="3" formControlName="notes"></textarea>
          </div>
          <label class="check sm:col-span-2">
            <input type="checkbox" class="switch" formControlName="is_active" />
            Aktif <span class="text-muted">(teslimatta seçilebilir)</span>
          </label>
          @if (error()) {
            <p class="form-error sm:col-span-2">{{ error() }}</p>
          }
        </div>
        <div modal-footer class="flex gap-2">
          <button type="button" class="btn btn-secondary" (click)="ref.close()">Vazgeç</button>
          <button type="submit" class="btn btn-primary" [disabled]="form.invalid || saving()">Kaydet</button>
        </div>
      </app-modal>
    </form>
  `,
})
export class HospitalDialog {
  protected readonly data = inject<{ hospital?: Hospital }>(DIALOG_DATA);
  protected readonly provinces = inject(ProvinceService).options();
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);

  protected readonly labels: Record<string, string> = HOSPITAL_LABELS;
  protected readonly types = HOSPITAL_TYPES;
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  private readonly h = this.data.hospital;
  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.h?.name ?? '', Validators.required],
    type: [this.h?.type ?? ('public' as HospitalType)],
    code: [this.h?.code ?? ''],
    city: [this.h?.city ?? ''],
    district: [this.h?.district ?? ''],
    address: [this.h?.address ?? ''],
    responsible_user_id: [this.h?.responsible_user_id ? String(this.h.responsible_user_id) : ''],
    notes: [this.h?.notes ?? ''],
    is_active: [this.h?.is_active ?? true],
  });

  // İlçe listesi seçili ile bağlı
  private readonly provinceService = inject(ProvinceService);
  private readonly provinceList = this.provinceService.provinces();
  protected readonly city = toSignal(this.form.controls.city.valueChanges, {
    initialValue: this.form.controls.city.value,
  });
  protected readonly districts = signal<SelectOption[]>([]);
  protected readonly districtsLoading = signal(false);

  // Sorumlu mümessil seçimi için personel listesi (personeli görme yetkisi gerekir)
  protected readonly canPickResponsible = inject(AuthService).can('users.view');
  protected readonly staff = signal<SelectOption[]>([]);

  constructor() {
    if (this.canPickResponsible) {
      this.http
        .get<Page<User>>(`${API_URL}/users`, { params: { per_page: 100, is_active: 1 } })
        .subscribe((p) => this.staff.set(p.data.map((u) => ({ value: String(u.id), label: u.name }))));
    }

    // Kullanıcı ili değiştirince eski ilçe geçersiz olur; temizle
    this.form.controls.city.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.form.controls.district.setValue(''));

    effect((onCleanup) => {
      const city = this.city();
      this.provinceList(); // il listesi geç yüklenirse yeniden dene
      const district = this.form.controls.district;

      if (!city) {
        this.districts.set([]);
        district.disable({ emitEvent: false });
        return;
      }
      district.enable({ emitEvent: false });
      this.districtsLoading.set(true);
      const sub = this.provinceService.districtsOf(city).subscribe((list) => {
        this.districts.set(list.map((d) => ({ value: d, label: d })));
        this.districtsLoading.set(false);
      });
      onCleanup(() => sub.unsubscribe());
    });
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    // Boş metinleri null gönder (benzersiz kod kontrolü boş metne takılmasın)
    const body: Record<string, unknown> = Object.fromEntries(
      Object.entries(this.form.getRawValue()).map(([k, v]) => [k, v === '' ? null : v]),
    );
    if (this.canPickResponsible) {
      body['responsible_user_id'] = body['responsible_user_id'] ? Number(body['responsible_user_id']) : null;
    } else {
      delete body['responsible_user_id'];
    }
    const req = this.h
      ? this.http.put(`${API_URL}/hospitals/${this.h.id}`, body)
      : this.http.post(`${API_URL}/hospitals`, body);

    req.subscribe({
      next: () => {
        this.toast.success('Hastane kaydedildi.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
