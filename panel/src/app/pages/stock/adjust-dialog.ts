import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage, qty } from '../../core/api';
import { Product, Stock, Warehouse } from '../../core/models';
import { ExpiryBadge } from '../../ui/expiry-badge';
import { ModalFrame } from '../../ui/modal-frame';
import { ProductPicker } from '../../ui/product-picker';
import { ToastService } from '../../ui/toast';

/**
 * Sayım düzeltmesi: belirli bir lot/seri satırı için sayılan miktar girilir,
 * sistem farkı hesaplayıp hareket olarak kaydeder.
 */
@Component({
  imports: [FormsModule, ModalFrame, ProductPicker, ExpiryBadge],
  template: `
    <app-modal title="Sayım düzeltmesi" subtitle="Saydığınız miktarı girin; fark otomatik kaydedilir.">
      <div class="space-y-4">
        @if (data.stock; as s) {
          <div class="rounded-xl bg-surface-2 p-4 text-sm">
            <div class="font-medium text-ink">{{ s.product?.name }}</div>
            <div class="text-muted">{{ s.warehouse?.name }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-2 font-mono text-[13px]">
              @if (s.serial_number) {
                <span class="badge">SN {{ s.serial_number }}</span>
              }
              @if (s.lot_number) {
                <span class="badge">LOT {{ s.lot_number }}</span>
              }
              <app-expiry-badge [date]="s.expiry_date" />
            </div>
            <div class="mt-2">
              Sistemdeki miktar: <strong class="text-ink">{{ qty(s.quantity) }} {{ s.product?.unit }}</strong>
            </div>
          </div>
        } @else {
          <div>
            <label class="label" for="adj-wh">Depo</label>
            <select id="adj-wh" class="select" [(ngModel)]="warehouseId">
              <option [ngValue]="null" disabled>Seçin</option>
              @for (w of warehouses(); track w.id) {
                <option [ngValue]="w.id">{{ w.name }}</option>
              }
            </select>
          </div>
          <app-product-picker label="Ürün" [(product)]="product" />
          @if (product && product.tracking !== 'none') {
            <div class="grid gap-3 sm:grid-cols-2">
              @if (product.tracking === 'serial') {
                <div class="sm:col-span-2">
                  <label class="label" for="adj-sn">Seri numarası</label>
                  <input id="adj-sn" class="input font-mono" [(ngModel)]="serial" />
                </div>
              }
              <div>
                <label class="label" for="adj-lot">Lot numarası</label>
                <input id="adj-lot" class="input font-mono" [(ngModel)]="lot" />
              </div>
              <div>
                <label class="label" for="adj-skt">SKT</label>
                <input id="adj-skt" class="input" type="date" [(ngModel)]="expiry" />
              </div>
              <p class="hint sm:col-span-2">
                Depoda bu ürünün tek lotu varsa boş bırakabilirsiniz. Sayımda sistemde olmayan bir lot bulduysanız bilgilerini girin.
              </p>
            </div>
          }
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

  /** Sayfa depoları henüz yüklemeden pencere açıldıysa kendisi yükler */
  protected readonly warehouses = signal<Warehouse[]>(this.data.warehouses);
  protected warehouseId: number | null = this.data.stock?.warehouse_id ?? null;

  constructor() {
    if (!this.data.stock && !this.data.warehouses.length) {
      this.http
        .get<Warehouse[]>(`${API_URL}/warehouses`, { params: { include_consignment: 1 } })
        .subscribe((w) => this.warehouses.set(w));
    }
  }
  protected product: Product | null = null;
  protected lot = '';
  protected serial = '';
  protected expiry = '';
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
    const s = this.data.stock;
    this.http
      .post<{ message: string }>(`${API_URL}/stocks/adjust`, {
        warehouse_id: this.warehouseId,
        product_id: s?.product_id ?? this.product?.id,
        // Tablodan açıldıysa o lot satırı; değilse girilen lot/seri
        stock_id: s?.id ?? null,
        lot_number: s ? null : this.lot || null,
        serial_number: s ? null : this.serial || null,
        expiry_date: s ? null : this.expiry || null,
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
