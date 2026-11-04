import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { API_URL, errorMessage, qty } from '../../core/api';
import { Movement, Page, Stock, WarehouseRef } from '../../core/models';
import { ToastService } from '../../ui/toast';

interface HospitalOption {
  id: number;
  name: string;
  city: string | null;
  district: string | null;
}

interface CartLine {
  stock: Stock;
  quantity: number;
  lot_number: string;
}

interface DeliveryGroup {
  batch: string;
  at: string;
  hospital: string;
  warehouse: string;
  lines: Movement[];
}

function newKey(): string {
  return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Mümessil teslimat ekranı: hastane seç → çantadaki ürünlerden miktar gir → kaydet.
 * Kaydedilen ürünler seçilen depodan düşülür; kim, ne zaman, hangi hastaneye bilgisi tutulur.
 */
@Component({
  imports: [DatePipe, FormsModule],
  templateUrl: './delivery.html',
})
export class DeliveryPage implements OnInit {
  /** /teslimat?hastane=3 ile ön seçim */
  readonly hastane = input<string>();

  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly qty = qty;

  protected readonly warehouses = signal<WarehouseRef[] | null>(null);
  protected readonly warehouseId = signal<number | null>(null);
  protected readonly hospitals = signal<HospitalOption[]>([]);
  protected readonly hospital = signal<HospitalOption | null>(null);
  protected readonly hospitalQuery = signal('');
  protected readonly stocks = signal<Stock[]>([]);
  protected readonly stockLoading = signal(false);
  protected readonly productQuery = signal('');
  protected readonly cart = signal<Map<number, CartLine>>(new Map());
  protected readonly recent = signal<DeliveryGroup[]>([]);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected reference = '';
  protected note = '';
  /** Aynı kaydın iki kez gönderilmesini önler; her başarılı kayıttan sonra yenilenir */
  private idempotencyKey = newKey();

  protected readonly filteredHospitals = computed(() => {
    const q = this.hospitalQuery().toLocaleLowerCase('tr').trim();
    const list = this.hospitals();
    return q
      ? list.filter((h) => `${h.name} ${h.city ?? ''} ${h.district ?? ''}`.toLocaleLowerCase('tr').includes(q))
      : list;
  });

  protected readonly filteredStocks = computed(() => {
    const q = this.productQuery().toLocaleLowerCase('tr').trim();
    const list = this.stocks();
    return q
      ? list.filter((s) => `${s.product?.name} ${s.product?.code} ${s.product?.barcode ?? ''}`.toLocaleLowerCase('tr').includes(q))
      : list;
  });

  protected readonly lines = computed(() => [...this.cart().values()].filter((l) => l.quantity > 0));
  protected readonly canSave = computed(
    () => !!this.hospital() && !!this.warehouseId() && this.lines().length > 0 && !this.saving(),
  );

  ngOnInit(): void {
    this.http.get<WarehouseRef[]>(`${API_URL}/mobile/warehouses`).subscribe((w) => {
      this.warehouses.set(w);
      if (w.length === 1) this.selectWarehouse(w[0].id);
    });
    this.http.get<HospitalOption[]>(`${API_URL}/mobile/hospitals`).subscribe((h) => {
      this.hospitals.set(h);
      const pre = Number(this.hastane());
      if (pre) this.hospital.set(h.find((x) => x.id === pre) ?? null);
    });
    this.loadRecent();
  }

  protected selectWarehouse(id: number | null): void {
    this.warehouseId.set(id);
    this.cart.set(new Map());
    this.stocks.set([]);
    if (id) this.loadStock();
  }

  private loadStock(): void {
    this.stockLoading.set(true);
    this.http
      .get<{ stocks: Stock[] }>(`${API_URL}/mobile/warehouses/${this.warehouseId()}/stock`)
      .subscribe({
        next: (r) => {
          this.stocks.set(r.stocks);
          this.stockLoading.set(false);
        },
        error: () => this.stockLoading.set(false),
      });
  }

  private loadRecent(): void {
    this.http
      .get<Page<Movement>>(`${API_URL}/mobile/usages`, { params: { per_page: 40 } })
      .subscribe((p) => {
        const groups = new Map<string, DeliveryGroup>();
        for (const m of p.data) {
          const g = groups.get(m.batch_uuid) ?? {
            batch: m.batch_uuid,
            at: m.created_at,
            hospital: m.hospital?.name ?? '—',
            warehouse: m.warehouse?.name ?? '',
            lines: [],
          };
          g.lines.push(m);
          groups.set(m.batch_uuid, g);
        }
        this.recent.set([...groups.values()].slice(0, 8));
      });
  }

  protected inCart(s: Stock): number {
    return this.cart().get(s.product_id)?.quantity ?? 0;
  }

  protected setQty(s: Stock, value: number): void {
    const max = Number(s.quantity);
    const q = Math.max(0, Math.min(max, Math.round((Number(value) || 0) * 100) / 100));
    const next = new Map(this.cart());
    const existing = next.get(s.product_id);
    if (q === 0) next.delete(s.product_id);
    else next.set(s.product_id, { stock: s, quantity: q, lot_number: existing?.lot_number ?? '' });
    this.cart.set(next);
  }

  protected setLot(line: CartLine, lot: string): void {
    const next = new Map(this.cart());
    next.set(line.stock.product_id, { ...line, lot_number: lot });
    this.cart.set(next);
  }

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const body = {
      warehouse_id: this.warehouseId(),
      hospital_id: this.hospital()!.id,
      reference: this.reference || null,
      note: this.note || null,
      items: this.lines().map((l) => ({
        product_id: l.stock.product_id,
        quantity: l.quantity,
        lot_number: l.lot_number || null,
      })),
    };

    this.http
      .post<{ message: string }>(`${API_URL}/mobile/usages`, body, { headers: { 'Idempotency-Key': this.idempotencyKey } })
      .subscribe({
        next: () => {
          this.toast.success(`${this.hospital()!.name} için teslimat kaydedildi, stoktan düşüldü.`);
          this.idempotencyKey = newKey();
          this.cart.set(new Map());
          this.reference = '';
          this.note = '';
          this.saving.set(false);
          this.loadStock();
          this.loadRecent();
        },
        error: (e) => {
          this.error.set(errorMessage(e));
          this.saving.set(false);
          // Stok başkası tarafından değişmiş olabilir
          if (e.status === 422) this.loadStock();
        },
      });
  }
}
