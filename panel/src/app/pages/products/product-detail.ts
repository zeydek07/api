import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { API_URL, qty } from '../../core/api';
import { Product, Stock, WAREHOUSE_LABELS } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';

@Component({
  imports: [ModalFrame],
  template: `
    <app-modal [title]="product()?.name ?? 'Ürün'" [subtitle]="product()?.code">
      @if (product(); as p) {
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl bg-surface-2 p-4 text-sm sm:grid-cols-3">
          <div>
            <dt class="text-xs text-muted">Barkod</dt>
            <dd class="mt-0.5 font-mono text-ink">{{ p.barcode || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Kategori</dt>
            <dd class="mt-0.5 text-ink">{{ p.category || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Marka</dt>
            <dd class="mt-0.5 text-ink">{{ p.brand || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Birim</dt>
            <dd class="mt-0.5 text-ink">{{ p.unit }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Minimum stok</dt>
            <dd class="mt-0.5 text-ink">{{ p.min_stock_level }} {{ p.unit }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Durum</dt>
            <dd class="mt-0.5">
              <span class="badge" [class.badge-ok]="p.is_active">{{ p.is_active ? 'Aktif' : 'Pasif' }}</span>
            </dd>
          </div>
        </dl>
        @if (p.description) {
          <p class="mt-4 text-sm text-ink-2">{{ p.description }}</p>
        }

        <h3 class="mb-3 mt-6 text-sm font-semibold">Depolardaki stok</h3>
        <div class="overflow-hidden rounded-xl border border-line">
          <table class="table">
            <tbody>
              @for (s of stocks(); track s.id) {
                <tr>
                  <td>
                    <div class="cell-title">{{ s.warehouse?.name }}</div>
                    <div class="cell-sub">{{ labels[s.warehouse?.type ?? ''] }}</div>
                  </td>
                  <td class="num font-medium text-ink">{{ qty(s.quantity) }} {{ p.unit }}</td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="2" class="py-6 text-center text-muted">Hiçbir depoda stok yok.</td>
                </tr>
              }
            </tbody>
            <tfoot>
              <tr class="bg-surface-2">
                <td class="font-semibold text-ink">Toplam</td>
                <td class="num font-semibold text-ink">{{ qty(total()) }} {{ p.unit }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      } @else {
        <div class="space-y-3">
          <div class="skeleton h-20"></div>
          <div class="skeleton h-32"></div>
        </div>
      }
      <div modal-footer>
        <button class="btn btn-secondary" (click)="ref.close()">Kapat</button>
      </div>
    </app-modal>
  `,
})
export class ProductDetailDialog {
  private readonly id = inject<number>(DIALOG_DATA);
  protected readonly ref = inject(DialogRef);
  protected readonly product = signal<Product | null>(null);
  protected readonly stocks = signal<Stock[]>([]);
  protected readonly total = signal(0);
  protected readonly labels: Record<string, string> = WAREHOUSE_LABELS;
  protected readonly qty = qty;

  constructor() {
    inject(HttpClient)
      .get<Product>(`${API_URL}/products/${this.id}`)
      .subscribe((p) => {
        this.product.set(p);
        this.stocks.set((p.stocks ?? []).filter((s) => Number(s.quantity) !== 0));
        this.total.set((p.stocks ?? []).reduce((sum, s) => sum + Number(s.quantity), 0));
      });
  }
}
