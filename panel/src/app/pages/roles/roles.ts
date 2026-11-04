import { Dialog } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { API_URL, errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { PermissionGroup, Role } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { PermissionMatrix } from '../../ui/permission-matrix';
import { ToastService } from '../../ui/toast';

/** Sol: roller. Sağ: seçili rolün sayfa sayfa yetkileri. */
@Component({
  imports: [FormsModule, PermissionMatrix, CanDirective],
  templateUrl: './roles.html',
})
export class RolesPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);

  protected readonly roles = signal<Role[]>([]);
  protected readonly groups = signal<PermissionGroup[]>([]);
  protected readonly selectedId = signal<number | 'new' | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected name = '';
  protected description = '';
  protected readonly permissions = signal<string[]>([]);
  private original = '';

  protected readonly selected = computed(() => this.roles().find((r) => r.id === this.selectedId()) ?? null);
  protected readonly isSuper = computed(() => this.selected()?.slug === 'super-admin');
  protected readonly canEdit = computed(() =>
    this.selectedId() === 'new' ? this.auth.can('roles.create') : this.auth.can('roles.update') && !this.isSuper(),
  );
  protected readonly allowed = computed(() =>
    this.auth.user()?.is_super_admin ? null : (this.auth.user()?.permissions ?? []),
  );
  protected readonly allKeys = computed(() => this.groups().flatMap((g) => g.permissions.map((p) => p.key)));
  protected readonly totalCount = computed(() => this.allKeys().length);

  constructor() {
    forkJoin({
      roles: this.http.get<Role[]>(`${API_URL}/roles`),
      groups: this.http.get<PermissionGroup[]>(`${API_URL}/permissions`),
    }).subscribe(({ roles, groups }) => {
      this.roles.set(roles);
      this.groups.set(groups);
      if (roles.length) this.select(roles[0]);
    });
  }

  protected dirty(): boolean {
    return this.snapshot() !== this.original;
  }

  private snapshot(): string {
    return JSON.stringify([this.name, this.description, [...this.permissions()].sort()]);
  }

  protected select(role: Role): void {
    this.selectedId.set(role.id);
    this.name = role.name;
    this.description = role.description ?? '';
    this.permissions.set([...role.permissions]);
    this.original = this.snapshot();
    this.error.set('');
  }

  protected startNew(): void {
    this.selectedId.set('new');
    this.name = '';
    this.description = '';
    this.permissions.set([]);
    this.original = this.snapshot();
    this.error.set('');
  }

  protected save(): void {
    const id = this.selectedId();
    const body = { name: this.name, description: this.description || null, permissions: this.permissions() };
    this.saving.set(true);
    this.error.set('');

    const req =
      id === 'new'
        ? this.http.post<Role>(`${API_URL}/roles`, body)
        : this.http.put<Role>(`${API_URL}/roles/${id}`, body);

    req.subscribe({
      next: (role) => {
        this.saving.set(false);
        this.toast.success('Rol kaydedildi. Değişiklik personelin bir sonraki isteğinde geçerli olur.');
        this.reload(role.id);
        if (role.id === this.auth.user()?.role?.id) this.auth.restore();
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }

  protected remove(): void {
    const role = this.selected();
    if (!role) return;
    confirm(this.dialog, {
      title: 'Rolü sil',
      message: `"${role.name}" rolü silinsin mi?`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/roles/${role.id}`).subscribe({
        next: () => {
          this.toast.success('Rol silindi.');
          this.reload(null);
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }

  private reload(selectId: number | null): void {
    this.http.get<Role[]>(`${API_URL}/roles`).subscribe((roles) => {
      this.roles.set(roles);
      const next = roles.find((r) => r.id === selectId) ?? roles[0];
      if (next) this.select(next);
    });
  }
}
