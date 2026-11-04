import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { Product, Warehouse } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { ProductPicker } from '../../ui/product-picker';
import { ToastService } from '../../ui/toast';

export type StockOperation = 'receive' | 'transfer';

interface Line {
  product: Product | null;
  quantity: number | null;
  lot_number: string;
  expiry_date: string;
}

/** Çok satırlı stok girişi veya depolar arası transfer */
@Component({
  imports: [FormsModule, ModalFrame, ProductPicker],
  templateUrl: './stock-operation-dialog.html',
})
export class StockOperationDialog {
  protected readonly data = inject<{ mode: StockOperation; warehouses: Warehouse[]; warehouseId: number | null }>(
    DIALOG_DATA,
  );
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);

  protected readonly isReceive = this.data.mode === 'receive';
  protected readonly activeWarehouses = this.data.warehouses.filter((w) => w.is_active);

  protected warehouseId: number | null = this.data.warehouseId;
  protected toWarehouseId: number | null = null;
  protected reference = '';
  protected note = '';
  protected readonly lines = signal<Line[]>([this.emptyLine()]);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly validLines = computed(() => this.lines().filter((l) => l.product && (l.quantity ?? 0) > 0));

  protected canSave(): boolean {
    if (this.saving() || !this.warehouseId || this.validLines().length === 0) return false;
    return this.isReceive || (!!this.toWarehouseId && this.toWarehouseId !== this.warehouseId);
  }

  protected addLine(): void {
    this.lines.update((l) => [...l, this.emptyLine()]);
  }

  protected removeLine(i: number): void {
    this.lines.update((l) => (l.length === 1 ? [this.emptyLine()] : l.filter((_, idx) => idx !== i)));
  }

  /** Satır içindeki bir alan değişince sayaçları güncelle */
  protected touch(): void {
    this.lines.update((l) => [...l]);
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const items = this.validLines().map((l) => ({
      product_id: l.product!.id,
      quantity: l.quantity,
      lot_number: l.lot_number || null,
      expiry_date: l.expiry_date || null,
    }));

    const req = this.isReceive
      ? this.http.post(`${API_URL}/stocks/receive`, {
          warehouse_id: this.warehouseId,
          reference: this.reference || null,
          note: this.note || null,
          items,
        })
      : this.http.post(`${API_URL}/stocks/transfer`, {
          from_warehouse_id: this.warehouseId,
          to_warehouse_id: this.toWarehouseId,
          note: this.note || null,
          items,
        });

    req.subscribe({
      next: () => {
        this.toast.success(this.isReceive ? 'Stok girişi yapıldı.' : 'Transfer yapıldı.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }

  private emptyLine(): Line {
    return { product: null, quantity: null, lot_number: '', expiry_date: '' };
  }
}
