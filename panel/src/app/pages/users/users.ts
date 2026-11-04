import { Dialog } from '@angular/cdk/dialog';
import { CdkMenu, CdkMenuItem, CdkMenuTrigger } from '@angular/cdk/menu';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { debounceTime, merge } from 'rxjs';
import { API_URL, errorMessage, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { Page, Role, User } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { ToastService } from '../../ui/toast';
import { UserDialog } from './user-dialog';

@Component({
  imports: [DatePipe, ReactiveFormsModule, CanDirective, Pagination, CdkMenuTrigger, CdkMenu, CdkMenuItem],
  templateUrl: './users.html',
})
export class UsersPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);

  protected readonly page = signal<Page<User> | null>(null);
  protected readonly roles = signal<Role[]>([]);
  protected readonly loading = signal(false);

  protected readonly q = new FormControl('', { nonNullable: true });
  protected readonly roleId = new FormControl<string>('', { nonNullable: true });
  protected readonly active = new FormControl<'' | '1' | '0'>('1', { nonNullable: true });
  private pageNo = 1;
  private perPage = 25;

  constructor() {
    merge(this.q.valueChanges.pipe(debounceTime(300)), this.roleId.valueChanges, this.active.valueChanges)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        this.pageNo = 1;
        this.load();
      });
    this.load();
    this.http.get<Role[]>(`${API_URL}/roles`).subscribe((r) => this.roles.set(r));
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      q: this.q.value,
      role_id: this.roleId.value,
      is_active: this.active.value,
      page: this.pageNo,
      per_page: this.perPage,
    });
    this.http.get<Page<User>>(`${API_URL}/users`, { params }).subscribe({
      next: (p) => {
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
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

  protected initials(name: string): string {
    return name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0]!.toLocaleUpperCase('tr'))
      .join('');
  }

  protected open(user?: User): void {
    openModal<boolean>(this.dialog, UserDialog, { userId: user?.id, roles: this.roles() }, 'xl').subscribe(
      (saved) => saved && this.load(),
    );
  }

  protected toggleActive(user: User): void {
    const next = !user.is_active;
    confirm(this.dialog, {
      title: next ? 'Personeli aktifleştir' : 'Personeli pasife al',
      message: next
        ? `${user.name} tekrar giriş yapabilecek.`
        : `${user.name} artık giriş yapamayacak ve açık oturumları (telefon dahil) hemen kapanacak.`,
      confirmText: next ? 'Aktifleştir' : 'Pasife al',
      danger: !next,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.put(`${API_URL}/users/${user.id}`, { is_active: next }).subscribe({
        next: () => {
          this.toast.success(next ? 'Personel aktifleştirildi.' : 'Personel pasife alındı.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }

  protected revokeTokens(user: User): void {
    confirm(this.dialog, {
      title: 'Tüm oturumları kapat',
      message: `${user.name} kullanıcısının tüm cihazlardaki oturumları kapatılacak (ör. telefon kaybolduysa). Tekrar giriş yapması gerekecek.`,
      confirmText: 'Oturumları kapat',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.post<{ message: string }>(`${API_URL}/users/${user.id}/revoke-tokens`, {}).subscribe({
        next: (r) => this.toast.success(r.message),
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }

  protected remove(user: User): void {
    confirm(this.dialog, {
      title: 'Personeli sil',
      message: `${user.name} silinsin mi? Geçmiş stok hareketleri korunur.`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/users/${user.id}`).subscribe({
        next: () => {
          this.toast.success('Personel silindi.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }
}
