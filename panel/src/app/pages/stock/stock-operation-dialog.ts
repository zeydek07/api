import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { parseGs1 } from '../../core/gs1';
import { Product, Warehouse } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { ProductPicker } from '../../ui/product-picker';
import { ToastService } from '../../ui/toast';

export type StockOperation = 'receive' | 'transfer';

interface Line {
  product: Product | null;
  quantity: number | null;
  lot_number: string;
  serial_number: string;
  expiry_date: string;
}

/** Çok satırlı stok girişi (karekodla) veya depolar arası transfer (lot otomatik) */
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
  // Hastaneye bırakma/geri alma imzalı teslimatla yapılır; burada yalnızca firma depoları
  /** Sayfa depoları henüz yüklemeden pencere açıldıysa kendisi yükler */
  private readonly warehouses = signal<Warehouse[]>(this.data.warehouses);
  protected readonly activeWarehouses = computed(() =>
    this.warehouses().filter((w) => w.is_active && w.type !== 'consignment'),
  );

  protected warehouseId: number | null = this.data.warehouseId;
  protected toWarehouseId: number | null = null;
  protected reference = '';
  protected note = '';
  protected scanText = '';
  protected readonly scanMessage = signal<{ text: string; error: boolean } | null>(null);
  protected readonly lines = signal<Line[]>([this.emptyLine()]);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  constructor() {
    if (!this.data.warehouses.length) {
      this.http.get<Warehouse[]>(`${API_URL}/warehouses`).subscribe((w) => this.warehouses.set(w));
    }
  }

  protected readonly validLines = computed(() => this.lines().filter((l) => l.product && !this.lineError(l) && (l.quantity ?? 0) > 0));

  /** Satır eksikse kısa açıklama; boş satır hata sayılmaz */
  protected lineError(l: Line): string | null {
    if (!l.product) return null;
    if (!l.quantity || l.quantity <= 0) return 'Miktar girin.';
    if (!this.isReceive) return null;
    if (l.product.tracking === 'serial' && !l.serial_number.trim()) return 'Seri numarası zorunlu.';
    if (l.product.tracking === 'lot' && !l.lot_number.trim()) return 'Lot numarası zorunlu.';
    if (l.expiry_date && l.expiry_date < new Date().toISOString().slice(0, 10)) return "SKT'si geçmiş ürün girilemez.";
    return null;
  }

  protected canSave(): boolean {
    if (this.saving() || !this.warehouseId || this.validLines().length === 0) return false;
    if (this.lines().some((l) => l.product && this.lineError(l))) return false;
    return this.isReceive || (!!this.toWarehouseId && this.toWarehouseId !== this.warehouseId);
  }

  /** Karekodu çöz, ürünü GTIN ile bul, satırı doldur */
  protected scan(): void {
    const raw = this.scanText;
    this.scanText = '';
    const g = parseGs1(raw);
    const code = g.gtin ?? g.plain;
    if (!code) {
      this.scanMessage.set({ text: 'Karekod okunamadı.', error: true });
      return;
    }

    this.http.get<Product>(`${API_URL}/products/barcode/${encodeURIComponent(code)}`).subscribe({
      next: (product) => {
        const lot = g.lot ?? '';
        const serial = g.serial ?? '';
        const expiry = g.expiry ?? '';
        const list = this.lines().filter((l) => l.product); // boş satırları at

        if (product.tracking === 'serial') {
          if (list.some((l) => l.product!.id === product.id && l.serial_number === serial && serial)) {
            this.scanMessage.set({ text: `${product.name} SN ${serial} zaten listede.`, error: true });
            return;
          }
          list.push({ product, quantity: 1, lot_number: lot, serial_number: serial, expiry_date: expiry });
        } else {
          const same = list.find((l) => l.product!.id === product.id && l.lot_number === lot && l.expiry_date === expiry);
          if (same) same.quantity = (same.quantity ?? 0) + 1;
          else list.push({ product, quantity: 1, lot_number: lot, serial_number: '', expiry_date: expiry });
        }
        this.lines.set(list);
        this.scanMessage.set({
          text: `✓ ${product.name}${serial ? ' · SN ' + serial : ''}${lot ? ' · LOT ' + lot : ''}${expiry ? ' · SKT ' + expiry : ''}`,
          error: false,
        });
      },
      error: () =>
        this.scanMessage.set({
          text: `Bu kod (${code}) hiçbir ürüne tanımlı değil. Ürün kartına karekod numarasını (GTIN) ekleyin.`,
          error: true,
        }),
    });
  }

  protected addLine(): void {
    this.lines.update((l) => [...l, this.emptyLine()]);
  }

  protected removeLine(i: number): void {
    this.lines.update((l) => (l.length === 1 ? [this.emptyLine()] : l.filter((_, idx) => idx !== i)));
  }

  /** Satır içindeki bir alan değişince sayaçları güncelle */
  protected touch(): void {
    this.lines.update((list) =>
      list.map((l) => (this.isReceive && l.product?.tracking === 'serial' ? { ...l, quantity: 1 } : l)),
    );
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const items = this.validLines().map((l) => ({
      product_id: l.product!.id,
      quantity: l.quantity,
      ...(this.isReceive
        ? {
            lot_number: l.lot_number.trim() || null,
            serial_number: l.serial_number.trim() || null,
            expiry_date: l.expiry_date || null,
          }
        : {}),
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
    return { product: null, quantity: null, lot_number: '', serial_number: '', expiry_date: '' };
  }
}
