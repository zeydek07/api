import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { catchError, forkJoin, of } from 'rxjs';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { Page, PermissionGroup, Role, User, WAREHOUSE_LABELS, Warehouse } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { PermissionMatrix } from '../../ui/permission-matrix';
import { ToastService } from '../../ui/toast';

@Component({
  imports: [ReactiveFormsModule, ModalFrame, PermissionMatrix],
  templateUrl: './user-dialog.html',
})
export class UserDialog {
  protected readonly data = inject<{ userId?: number; roles: Role[] }>(DIALOG_DATA);
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);
  protected readonly whLabels: Record<string, string> = WAREHOUSE_LABELS;

  protected readonly tab = signal<'info' | 'access'>('info');
  protected readonly ready = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly managers = signal<User[]>([]);
  protected readonly warehouses = signal<Warehouse[]>([]);
  protected readonly groups = signal<PermissionGroup[]>([]);
  protected readonly directPermissions = signal<string[]>([]);

  protected readonly isEdit = !!this.data.userId;
  protected readonly canSeeWarehouses = this.auth.can('warehouses.view');
  protected readonly canSeePermissions = this.auth.can('roles.view');

  /** Süper yönetici olmayan yönetici, süper yönetici rolünü atayamaz */
  protected readonly roles = this.data.roles.filter(
    (r) => this.auth.user()?.is_super_admin || r.slug !== 'super-admin',
  );

  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    password: ['', this.isEdit ? [Validators.minLength(8)] : [Validators.required, Validators.minLength(8)]],
    role_id: [null as number | null, Validators.required],
    manager_id: [null as number | null],
    warehouse_ids: [[] as number[]],
    is_active: [true],
  });

  private readonly roleId = toSignal(this.form.controls.role_id.valueChanges, {
    initialValue: this.form.controls.role_id.value,
  });
  private readonly warehouseIds = toSignal(this.form.controls.warehouse_ids.valueChanges, {
    initialValue: this.form.controls.warehouse_ids.value,
  });

  /** Seçili rolden gelen yetkiler tabloda kilitli gösterilir */
  protected readonly rolePermissions = computed(() => {
    const role = this.data.roles.find((r) => r.id === Number(this.roleId()));
    if (!role) return [];
    if (role.slug === 'super-admin') return this.groups().flatMap((g) => g.permissions.map((p) => p.key));
    return role.permissions;
  });

  protected readonly allowedPermissions = computed(() =>
    this.auth.user()?.is_super_admin ? null : (this.auth.user()?.permissions ?? []),
  );

  protected readonly extraCount = computed(
    () => this.directPermissions().filter((p) => !this.rolePermissions().includes(p)).length,
  );

  constructor() {
    forkJoin({
      user: this.isEdit ? this.http.get<User>(`${API_URL}/users/${this.data.userId}`) : of(null),
      managers: this.http
        .get<Page<User>>(`${API_URL}/users`, { params: { per_page: 100, is_active: 1 } })
        .pipe(catchError(() => of({ data: [] as User[] } as Page<User>))),
      warehouses: this.canSeeWarehouses
        ? this.http.get<Warehouse[]>(`${API_URL}/warehouses`).pipe(catchError(() => of([] as Warehouse[])))
        : of([] as Warehouse[]),
      groups: this.canSeePermissions
        ? this.http.get<PermissionGroup[]>(`${API_URL}/permissions`).pipe(catchError(() => of([] as PermissionGroup[])))
        : of([] as PermissionGroup[]),
    }).subscribe({
      next: ({ user, managers, warehouses, groups }) => {
        this.managers.set(managers.data.filter((m) => m.id !== this.data.userId));
        this.warehouses.set(warehouses);
        this.groups.set(groups);
        if (user) {
          this.form.patchValue({
            name: user.name,
            email: user.email,
            phone: user.phone ?? '',
            role_id: user.role_id,
            manager_id: user.manager_id,
            warehouse_ids: (user.warehouses ?? []).map((w) => w.id),
            is_active: user.is_active,
          });
          this.directPermissions.set(user.direct_permissions ?? []);
        }
        this.ready.set(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.ready.set(true);
      },
    });
  }

  protected hasWarehouse(id: number): boolean {
    return this.warehouseIds().includes(id);
  }

  protected toggleWarehouse(id: number, checked: boolean): void {
    const set = new Set(this.form.controls.warehouse_ids.value);
    if (checked) set.add(id);
    else set.delete(id);
    this.form.controls.warehouse_ids.setValue([...set]);
  }

  protected save(): void {
    if (this.form.invalid) {
      this.tab.set('info');
      this.form.markAllAsTouched();
      this.error.set('Zorunlu alanları doldurun.');
      return;
    }
    this.saving.set(true);
    this.error.set('');
    const raw = this.form.getRawValue();
    const body: Record<string, unknown> = {
      ...raw,
      role_id: Number(raw.role_id),
      manager_id: raw.manager_id ? Number(raw.manager_id) : null,
      phone: raw.phone || null,
      // Rolden zaten gelen yetkileri ayrıca kaydetmeye gerek yok
      ...(this.canSeePermissions
        ? { permissions: this.directPermissions().filter((p) => !this.rolePermissions().includes(p)) }
        : {}),
    };
    if (!this.canSeeWarehouses) delete body['warehouse_ids'];
    if (this.isEdit && !raw.password) delete body['password'];

    const req = this.isEdit
      ? this.http.put(`${API_URL}/users/${this.data.userId}`, body)
      : this.http.post(`${API_URL}/users`, body);

    req.subscribe({
      next: () => {
        this.toast.success('Personel kaydedildi.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
