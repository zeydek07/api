import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage, qty } from '../../core/api';
import { Product, Stock, Warehouse } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { ProductPicker } from '../../ui/product-picker';
import { ToastService } from '../../ui/toast';

/** Sayım sonucu girilir; sistem farkı otomatik hesaplayıp hareket olarak kaydeder. */
@Component({
  imports: [FormsModule, ModalFrame, ProductPicker],
  template: `
    <app-modal title="Sayım düzeltmesi" subtitle="Saydığınız miktarı girin; fark otomatik kaydedilir.">
      <div class="space-y-4">
        @if (data.stock; as s) {
          <div class="rounded-xl bg-surface-2 p-4">
            <div class="font-medium text-ink">{{ s.product?.name }}</div>
            <div class="text-sm text-muted">{{ s.warehouse?.name }}</div>
            <div class="mt-2 text-sm">
              Sistemdeki miktar: <strong class="text-ink">{{ qty(s.quantity) }} {{ s.product?.unit }}</strong>
            </div>
          </div>
        } @else {
          <div>
            <label class="label" for="adj-wh">Depo</label>
            <select id="adj-wh" class="select" [(ngModel)]="warehouseId">
              <option [ngValue]="null" disabled>Seçin</option>
              @for (w of data.warehouses; track w.id) {
                <option [ngValue]="w.id">{{ w.name }}</option>
              }
            </select>
          </div>
          <app-product-picker label="Ürün" [(product)]="product" />
        }
        <div>
          <label class="label" for="adj-qty">Sayılan miktar</label>
          <input id="adj-qty" class="input tabular-nums" type="number" min="0" [(ngModel)]="counted" />
          @if (diff() !== null && diff() !== 0) {
            <p class="hint" [class.text-danger]="diff()! < 0" [class.text-ok]="diff()! > 0">
              Fark: {{ diff()! > 0 ? '+' : '' }}{{ qty(diff()) }} {{ data.stock?.product?.unit }}
            </p>
          }
        </div>
        <div>
          <label class="label" for="adj-note">Gerekçe *</label>
          <input id="adj-note" class="input" [(ngModel)]="note" placeholder="ör. Aylık sayım, hasarlı ürün imhası" />
        </div>
        @if (error()) {
          <p class="form-error">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close()">Vazgeç</button>
        <button class="btn btn-primary" (click)="save()" [disabled]="!canSave()">Kaydet</button>
      </div>
    </app-modal>
  `,
})
export class AdjustDialog {
  protected readonly data = inject<{ stock?: Stock; warehouses: Warehouse[] }>(DIALOG_DATA);
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);
  protected readonly qty = qty;

  protected warehouseId: number | null = this.data.stock?.warehouse_id ?? null;
  protected product: Product | null = null;
  protected counted: number | null = this.data.stock ? Number(this.data.stock.quantity) : null;
  protected note = '';
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected diff(): number | null {
    if (!this.data.stock || this.counted === null) return null;
    return Math.round((this.counted - Number(this.data.stock.quantity)) * 100) / 100;
  }

  protected canSave(): boolean {
    const productId = this.data.stock?.product_id ?? this.product?.id;
    return (
      !this.saving() &&
      !!this.warehouseId &&
      !!productId &&
      this.counted !== null &&
      this.counted >= 0 &&
      !!this.note.trim()
    );
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    this.http
      .post<{ message: string }>(`${API_URL}/stocks/adjust`, {
        warehouse_id: this.warehouseId,
        product_id: this.data.stock?.product_id ?? this.product?.id,
        counted_quantity: this.counted,
        note: this.note,
      })
      .subscribe({
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
