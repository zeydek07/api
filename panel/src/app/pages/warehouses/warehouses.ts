import { Dialog } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage } from '../../core/api';
import { CanDirective } from '../../core/can.directive';
import { WAREHOUSE_LABELS, Warehouse, WarehouseType } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';
import { WarehouseDialog } from './warehouse-dialog';

const TYPE_ICONS: Record<WarehouseType, string> = { central: 'warehouse', branch: 'store', field: 'local_shipping' };

@Component({
  imports: [RouterLink, CanDirective],
  template: `
    <div class="page-head">
      <div class="flex-1">
        <h1 class="page-title">Depolar</h1>
        <p class="page-sub">
          Merkez depo, şube depoları ve her saha personelinin çantası veya aracı. Personel yalnızca kendisine atanmış
          depolardan ürün düşebilir.
        </p>
      </div>
      <button class="btn btn-primary" *appCan="'warehouses.create'" (click)="open()">
        <span class="icon">add</span> Yeni depo
      </button>
    </div>

    <div class="mb-5 inline-flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="Tür filtresi">
      @for (t of filters; track t.v) {
        <button
          type="button"
          class="rounded-md px-3 py-1.5 text-[13px] font-medium transition"
          [class]="type() === t.v ? 'bg-primary-soft text-primary-ink' : 'text-muted hover:text-ink'"
          [attr.aria-pressed]="type() === t.v"
          (click)="type.set(t.v)"
        >
          {{ t.l }} <span class="ml-1 tabular-nums opacity-70">{{ count(t.v) }}</span>
        </button>
      }
    </div>

    @if (loading()) {
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @for (i of [1, 2, 3]; track i) {
          <div class="card h-44 p-5"><div class="skeleton h-full"></div></div>
        }
      </div>
    } @else {
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @for (w of visible(); track w.id) {
          <div class="card flex flex-col p-5" [class.opacity-60]="!w.is_active">
            <div class="flex items-start gap-3">
              <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                <span class="icon">{{ icons[w.type] }}</span>
              </div>
              <div class="min-w-0 flex-1">
                <div class="truncate font-semibold text-ink">{{ w.name }}</div>
                <div class="text-xs text-muted">
                  <span class="font-mono">{{ w.code }}</span> · {{ labels[w.type] }}
                </div>
              </div>
              @if (!w.is_active) {
                <span class="badge">Pasif</span>
              }
            </div>
            @if (w.address) {
              <p class="mt-3 flex gap-1.5 text-xs text-muted">
                <span class="icon text-[16px]">location_on</span>{{ w.address }}
              </p>
            }
            <div class="mt-4 flex-1">
              <div class="mb-1.5 text-xs font-medium text-muted">Ürün düşebilen personel</div>
              <div class="flex flex-wrap gap-1.5">
                @for (u of w.users ?? []; track u.id) {
                  <span class="badge">{{ u.name }}</span>
                } @empty {
                  <span class="text-xs text-muted">Atanmış personel yok</span>
                }
              </div>
            </div>
            <div class="mt-5 flex items-center gap-1 border-t border-line pt-3">
              <a class="btn btn-ghost btn-sm" *appCan="'stock.view'" routerLink="/stok" [queryParams]="{ depo: w.id }">
                <span class="icon">inventory_2</span> Stoğu gör
              </a>
              <span class="flex-1"></span>
              <button class="btn-icon" *appCan="'warehouses.update'" (click)="open(w)" aria-label="Düzenle" title="Düzenle">
                <span class="icon">edit</span>
              </button>
              <button class="btn-icon danger" *appCan="'warehouses.delete'" (click)="remove(w)" aria-label="Sil" title="Sil">
                <span class="icon">delete</span>
              </button>
            </div>
          </div>
        } @empty {
          <div class="card col-span-full py-16 text-center">
            <span class="icon text-[40px] text-muted">warehouse</span>
            <p class="mt-2 font-medium">Bu türde depo yok</p>
          </div>
        }
      </div>
    }
  `,
})
export class WarehousesPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);

  protected readonly rows = signal<Warehouse[]>([]);
  protected readonly loading = signal(true);
  protected readonly type = signal<WarehouseType | ''>('');
  protected readonly labels: Record<string, string> = WAREHOUSE_LABELS;
  protected readonly icons: Record<string, string> = TYPE_ICONS;
  protected readonly filters: { v: WarehouseType | ''; l: string }[] = [
    { v: '', l: 'Tümü' },
    { v: 'central', l: 'Merkez' },
    { v: 'branch', l: 'Şube' },
    { v: 'field', l: 'Personel' },
  ];

  protected readonly visible = computed(() => {
    const t = this.type();
    return t ? this.rows().filter((w) => w.type === t) : this.rows();
  });

  constructor() {
    this.load();
  }

  protected count(t: WarehouseType | ''): number {
    return t ? this.rows().filter((w) => w.type === t).length : this.rows().length;
  }

  private load(): void {
    this.http.get<Warehouse[]>(`${API_URL}/warehouses`).subscribe({
      next: (w) => {
        this.rows.set(w);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected open(warehouse?: Warehouse): void {
    openModal<boolean>(this.dialog, WarehouseDialog, warehouse, 'md').subscribe((saved) => saved && this.load());
  }

  protected remove(w: Warehouse): void {
    confirm(this.dialog, {
      title: 'Depoyu sil',
      message: `"${w.name}" silinsin mi? Hareket görmüş depolar silinemez, pasife alınabilir.`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/warehouses/${w.id}`).subscribe({
        next: () => {
          this.toast.success('Depo silindi.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }
}
