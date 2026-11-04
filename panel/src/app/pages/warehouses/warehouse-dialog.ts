import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { Page, User, WAREHOUSE_LABELS, Warehouse, WarehouseType } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { ToastService } from '../../ui/toast';

@Component({
  imports: [ReactiveFormsModule, ModalFrame],
  template: `
    <form [formGroup]="form" (ngSubmit)="save()">
      <app-modal [title]="data ? 'Depoyu düzenle' : 'Yeni depo'">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="w-name">Depo adı *</label>
            <input id="w-name" class="input" formControlName="name" placeholder="ör. Ali Yılmaz - Araç" />
          </div>
          <div>
            <label class="label" for="w-code">Kod *</label>
            <input id="w-code" class="input font-mono" formControlName="code" placeholder="ör. ARAC-01" />
          </div>
          <div>
            <label class="label" for="w-type">Tür</label>
            <select id="w-type" class="select" formControlName="type">
              @for (t of types; track t) {
                <option [value]="t">{{ labels[t] }}</option>
              }
            </select>
          </div>
          <div>
            <label class="label" for="w-addr">Adres</label>
            <input id="w-addr" class="input" formControlName="address" />
          </div>
          @if (canListUsers) {
            <div class="sm:col-span-2">
              <span class="label">Bu depodan ürün düşebilecek personel</span>
              <div class="max-h-52 overflow-y-auto rounded-lg border border-line p-1">
                @for (u of users(); track u.id) {
                  <label class="flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 hover:bg-surface-2">
                    <input type="checkbox" class="size-4" [checked]="hasUser(u.id)" (change)="toggleUser(u.id, $any($event.target).checked)" />
                    <span class="text-sm text-ink">{{ u.name }}</span>
                    <span class="ml-auto text-xs text-muted">{{ u.role?.name }}</span>
                  </label>
                } @empty {
                  <p class="p-3 text-sm text-muted">Personel yükleniyor…</p>
                }
              </div>
            </div>
          }
          <label class="check sm:col-span-2">
            <input type="checkbox" class="switch" formControlName="is_active" /> Aktif
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
export class WarehouseDialog {
  protected readonly data = inject<Warehouse | undefined>(DIALOG_DATA);
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);

  protected readonly labels: Record<string, string> = WAREHOUSE_LABELS;
  protected readonly types = Object.keys(WAREHOUSE_LABELS) as WarehouseType[];
  protected readonly canListUsers = inject(AuthService).can('users.view');
  protected readonly users = signal<User[]>([]);
  protected readonly userIds = signal<number[]>((this.data?.users ?? []).map((u) => u.id));
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data?.name ?? '', Validators.required],
    code: [this.data?.code ?? '', Validators.required],
    type: [this.data?.type ?? ('field' as WarehouseType)],
    address: [this.data?.address ?? ''],
    is_active: [this.data?.is_active ?? true],
  });

  constructor() {
    if (this.canListUsers) {
      this.http
        .get<Page<User>>(`${API_URL}/users`, { params: { per_page: 100, is_active: 1 } })
        .subscribe((p) => this.users.set(p.data));
    }
  }

  protected hasUser(id: number): boolean {
    return this.userIds().includes(id);
  }

  protected toggleUser(id: number, checked: boolean): void {
    this.userIds.update((ids) => (checked ? [...new Set([...ids, id])] : ids.filter((x) => x !== id)));
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const raw = this.form.getRawValue();
    const body: Record<string, unknown> = { ...raw, address: raw.address || null };
    if (this.canListUsers) body['user_ids'] = this.userIds();

    const req = this.data
      ? this.http.put(`${API_URL}/warehouses/${this.data.id}`, body)
      : this.http.post(`${API_URL}/warehouses`, body);

    req.subscribe({
      next: () => {
        this.toast.success('Depo kaydedildi.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
