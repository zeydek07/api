import { HttpClient } from '@angular/common/http';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage, qty } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { parseGs1 } from '../../core/gs1';
import { compressImage } from '../../core/image';
import { CapturedLocation, LOCATION_ERROR_LABELS, capturePosition } from '../../core/location';
import {
  ConsignmentLocation,
  Delivery,
  DeliveryType,
  Hospital,
  HospitalContact,
  Page,
  Stock,
  WarehouseRef,
} from '../../core/models';
import { ExpiryBadge, expiryLevel } from '../../ui/expiry-badge';
import { SignaturePad } from '../../ui/signature-pad';
import { ToastService } from '../../ui/toast';

function newKey(): string {
  return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Konsinye teslimat ekranı.
 * Teslim et: mümessilin aracından hastanedeki lokasyona.
 * Geri al: hastanedeki lokasyondan araca (SKT'si yaklaşan, kullanılmayan ürünler).
 */
@Component({
  imports: [FormsModule, RouterLink, ExpiryBadge, SignaturePad],
  templateUrl: './delivery.html',
})
export class DeliveryPage implements OnInit {
  /** /teslimat?hastane=3 ile ön seçim */
  readonly hastane = input<string>();

  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly auth = inject(AuthService);
  protected readonly qty = qty;

  protected readonly mode = signal<DeliveryType>('delivery');
  protected readonly isReturn = computed(() => this.mode() === 'return');

  // 1. Hastane
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly hospitalQuery = signal('');
  protected readonly hospital = signal<Hospital | null>(null);
  // 2. Lokasyon
  protected readonly locations = signal<ConsignmentLocation[]>([]);
  protected readonly location = signal<ConsignmentLocation | null>(null);
  // Araç (mümessilin deposu)
  protected readonly vehicles = signal<WarehouseRef[] | null>(null);
  protected readonly vehicle = signal<WarehouseRef | null>(null);
  // 3. Teslim alan
  protected readonly contacts = signal<HospitalContact[]>([]);
  protected readonly contact = signal<HospitalContact | null>(null);
  protected readonly otherPerson = signal(false);
  protected otherName = '';
  protected otherTitle = '';
  protected readonly addingContact = signal(false);
  protected newContact = { name: '', title: '', phone: '' };
  // 4. Ürünler
  protected readonly rows = signal<Stock[]>([]);
  protected readonly rowsLoading = signal(false);
  protected readonly productQuery = signal('');
  protected readonly cart = signal<Map<number, number>>(new Map());
  protected scanText = '';
  protected readonly scanMessage = signal<{ text: string; error: boolean } | null>(null);
  // 5. Kanıt
  protected readonly signature = signal<string | null>(null);
  protected readonly photo = signal<string | null>(null);
  // Konum sayfa açılınca önizleme için, kaydet anında da belge için yeniden alınır
  protected readonly position = signal<CapturedLocation | null>(null);
  protected readonly positionState = signal<'pending' | 'ok' | 'none'>('pending');
  protected readonly locationLabels = LOCATION_ERROR_LABELS;
  protected note = '';

  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly done = signal<Delivery | null>(null);
  private idempotencyKey = newKey();

  protected readonly filteredHospitals = computed(() => {
    const q = this.hospitalQuery().toLocaleLowerCase('tr').trim();
    const list = this.hospitals();
    return q ? list.filter((h) => `${h.name} ${h.city ?? ''} ${h.district ?? ''}`.toLocaleLowerCase('tr').includes(q)) : list;
  });

  /** Seçili lokasyona bağlı kişiler ve lokasyonu boş olanlar */
  protected readonly visibleContacts = computed(() => {
    const loc = this.location()?.id;
    return this.contacts().filter((c) => c.is_active && (!c.location_id || c.location_id === loc));
  });

  protected readonly filteredRows = computed(() => {
    const q = this.productQuery().toLocaleLowerCase('tr').trim();
    const list = [...this.rows()].sort(
      (a, b) =>
        (a.product?.name ?? '').localeCompare(b.product?.name ?? '', 'tr') ||
        (a.expiry_date ?? '9999').localeCompare(b.expiry_date ?? '9999'),
    );
    if (!q) return list;
    return list.filter((s) =>
      `${s.product?.name} ${s.product?.code} ${s.lot_number ?? ''} ${s.serial_number ?? ''}`.toLocaleLowerCase('tr').includes(q),
    );
  });

  protected readonly lines = computed(() =>
    [...this.cart().entries()]
      .map(([id, q]) => ({ stock: this.rows().find((r) => r.id === id)!, quantity: q }))
      .filter((l) => l.stock && l.quantity > 0),
  );

  protected readonly totalUnits = computed(() => this.lines().reduce((s, l) => s + l.quantity, 0));

  protected readonly receiverReady = computed(() =>
    this.otherPerson() ? true : !!this.contact(),
  );

  protected canSubmit(): boolean {
    if (this.saving() || !this.hospital() || !this.location() || !this.vehicle() || this.lines().length === 0) return false;
    if (this.otherPerson() ? !this.otherName.trim() : !this.contact()) return false;
    return this.isReturn() || !!this.signature();
  }

  /** Eksik olan ilk adım (düğmenin altında gösterilir) */
  protected missing(): string {
    if (!this.hospital()) return 'Hastane seçin';
    if (!this.location()) return 'Lokasyon seçin';
    if (!this.vehicle()) return 'Araç seçin';
    if (this.otherPerson() ? !this.otherName.trim() : !this.contact())
      return this.isReturn() ? 'Teslim eden kişiyi seçin' : 'Teslim alan kişiyi seçin';
    if (!this.lines().length) return 'En az bir ürün ekleyin';
    if (!this.isReturn() && !this.signature()) return 'Teslim alanın imzası gerekli';
    return '';
  }

  ngOnInit(): void {
    this.http
      .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { is_active: 1, per_page: 100 } })
      .subscribe((p) => {
        const me = this.auth.user()?.id;
        // Sorumlu olduğum hastaneler önce
        const sorted = [...p.data].sort(
          (a, b) => Number(b.responsible_user_id === me) - Number(a.responsible_user_id === me) || a.name.localeCompare(b.name, 'tr'),
        );
        this.hospitals.set(sorted);
        const pre = Number(this.hastane());
        const h = pre ? sorted.find((x) => x.id === pre) : null;
        if (h) this.selectHospital(h);
      });

    this.http.get<WarehouseRef[]>(`${API_URL}/mobile/warehouses`).subscribe((w) => {
      this.vehicles.set(w);
      if (w.length === 1) this.selectVehicle(w[0]);
    });

    this.locate();
  }

  protected locate(): Promise<CapturedLocation> {
    this.positionState.set('pending');
    return capturePosition().then((p) => {
      this.position.set(p);
      this.positionState.set(p.latitude !== undefined ? 'ok' : 'none');
      return p;
    });
  }

  protected setMode(m: DeliveryType): void {
    if (this.mode() === m) return;
    this.mode.set(m);
    this.cart.set(new Map());
    this.scanMessage.set(null);
    this.loadRows();
  }

  protected selectHospital(h: Hospital | null): void {
    this.hospital.set(h);
    this.location.set(null);
    this.locations.set([]);
    this.contact.set(null);
    this.contacts.set([]);
    this.otherPerson.set(false);
    if (this.isReturn()) {
      this.cart.set(new Map());
      this.rows.set([]);
    }
    if (!h) return;

    this.http.get<ConsignmentLocation[]>(`${API_URL}/hospitals/${h.id}/locations`).subscribe((list) => {
      const active = list.filter((l) => l.is_active);
      this.locations.set(active);
      if (active.length === 1) this.selectLocation(active[0]);
    });
    this.loadContacts(h.id);
  }

  private loadContacts(hospitalId: number, select?: number): void {
    this.http.get<HospitalContact[]>(`${API_URL}/hospitals/${hospitalId}/contacts`).subscribe((c) => {
      this.contacts.set(c);
      if (select) this.contact.set(c.find((x) => x.id === select) ?? null);
    });
  }

  protected selectLocation(l: ConsignmentLocation): void {
    this.location.set(l);
    if (this.contact()?.location_id && this.contact()!.location_id !== l.id) this.contact.set(null);
    if (this.isReturn()) this.loadRows();
  }

  protected selectVehicle(v: WarehouseRef | null): void {
    this.vehicle.set(v);
    if (!this.isReturn()) this.loadRows();
  }

  /** Teslimde araçtaki stok, geri almada hastane lokasyonundaki stok */
  private loadRows(): void {
    this.cart.set(new Map());
    this.rows.set([]);
    const url = this.isReturn()
      ? this.hospital() && this.location()
        ? `${API_URL}/hospitals/${this.hospital()!.id}/consignment?location_id=${this.location()!.id}`
        : null
      : this.vehicle()
        ? `${API_URL}/mobile/warehouses/${this.vehicle()!.id}/stock`
        : null;
    if (!url) return;

    this.rowsLoading.set(true);
    this.http.get<{ stocks?: Stock[]; rows?: Stock[] }>(url).subscribe({
      next: (r) => {
        this.rows.set(r.stocks ?? r.rows ?? []);
        this.rowsLoading.set(false);
      },
      error: () => this.rowsLoading.set(false),
    });
  }

  // ---------------------------------------------------------------- kişiler

  protected pickContact(c: HospitalContact): void {
    this.otherPerson.set(false);
    this.contact.set(this.contact()?.id === c.id ? null : c);
  }

  protected useOtherPerson(): void {
    this.contact.set(null);
    this.addingContact.set(false);
    this.otherPerson.set(true);
  }

  protected saveNewContact(): void {
    const h = this.hospital();
    if (!h || !this.newContact.name.trim()) return;
    this.http
      .post<HospitalContact>(`${API_URL}/hospitals/${h.id}/contacts`, {
        name: this.newContact.name.trim(),
        title: this.newContact.title.trim() || null,
        phone: this.newContact.phone.trim() || null,
        location_id: this.location()?.id ?? null,
      })
      .subscribe({
        next: (c) => {
          this.toast.success(`${c.name} hastane personeline eklendi.`);
          this.addingContact.set(false);
          this.otherPerson.set(false);
          this.newContact = { name: '', title: '', phone: '' };
          this.loadContacts(h.id, c.id);
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
  }

  // ---------------------------------------------------------------- ürünler

  protected inCart(s: Stock): number {
    return this.cart().get(s.id) ?? 0;
  }

  /** Teslimde SKT'si geçmiş ürün bırakılamaz; geri almada alınabilir */
  protected blocked(s: Stock): boolean {
    return !this.isReturn() && expiryLevel(s.expiry_date) === 'expired';
  }

  protected setQty(s: Stock, value: number): void {
    if (this.blocked(s)) return;
    const max = Number(s.quantity);
    const q = Math.max(0, Math.min(max, Math.round((Number(value) || 0) * 100) / 100));
    const next = new Map(this.cart());
    if (q === 0) next.delete(s.id);
    else next.set(s.id, q);
    this.cart.set(next);
  }

  protected selectAll(s: Stock): void {
    this.setQty(s, Number(s.quantity));
  }

  /** Karekod: ürün + seri/lot eşleşen satıra 1 ekler; lot yoksa SKT'si en yakın uygun satıra */
  protected scan(): void {
    const g = parseGs1(this.scanText);
    this.scanText = '';
    const gtin = g.gtin;
    const code = g.plain;
    if (!gtin && !code) {
      this.scanMessage.set({ text: 'Karekod okunamadı.', error: true });
      return;
    }
    const sameProduct = this.rows().filter(
      (r) => (gtin && r.product?.gtin === gtin) || (code && r.product?.barcode === code),
    );
    if (!sameProduct.length) {
      this.scanMessage.set({
        text: this.isReturn() ? 'Bu ürün seçili lokasyonda yok.' : 'Bu ürün aracınızda yok.',
        error: true,
      });
      return;
    }
    let candidates = sameProduct;
    if (g.serial) candidates = candidates.filter((r) => r.serial_number === g.serial);
    else if (g.lot) candidates = candidates.filter((r) => r.lot_number === g.lot && (!g.expiry || r.expiry_date?.slice(0, 10) === g.expiry));
    const row = candidates
      .filter((r) => !this.blocked(r) && this.inCart(r) < Number(r.quantity))
      .sort((a, b) => (a.expiry_date ?? '9999').localeCompare(b.expiry_date ?? '9999'))[0];

    if (!row) {
      const name = sameProduct[0].product?.name;
      const expired = candidates.some((r) => this.blocked(r));
      this.scanMessage.set({
        text: g.serial
          ? `${name} SN ${g.serial} ${candidates.length ? 'zaten eklendi.' : 'burada yok.'}`
          : expired
            ? `${name} ${g.lot ? 'LOT ' + g.lot : ''}: SKT'si geçmiş, hastaneye bırakılamaz.`
            : `${name}${g.lot ? ' LOT ' + g.lot : ''}: eklenebilecek başka ürün yok.`,
        error: true,
      });
      return;
    }
    this.setQty(row, this.inCart(row) + 1);
    this.scanMessage.set({
      text: `✓ ${row.product?.name}${row.serial_number ? ' · SN ' + row.serial_number : ''}${row.lot_number ? ' · LOT ' + row.lot_number : ''} (${this.inCart(row)}/${qty(row.quantity)})`,
      error: false,
    });
  }

  // ---------------------------------------------------------------- kanıt

  protected async onPhoto(e: Event): Promise<void> {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    try {
      this.photo.set(await compressImage(file));
    } catch {
      this.toast.error('Fotoğraf okunamadı.');
    }
  }

  // ---------------------------------------------------------------- kaydet

  protected async submit(): Promise<void> {
    if (!this.canSubmit()) return;
    this.saving.set(true);
    this.error.set('');
    // İşlem anındaki konum; alınamazsa nedeni gönderilir ve belge "konum alınamadı" olarak işaretlenir
    const location = await this.locate();
    const body = {
      type: this.mode(),
      hospital_id: this.hospital()!.id,
      location_id: this.location()!.id,
      vehicle_id: this.vehicle()!.id,
      contact_id: this.otherPerson() ? null : this.contact()?.id,
      receiver_name: this.otherPerson() ? this.otherName.trim() : null,
      receiver_title: this.otherPerson() ? this.otherTitle.trim() || null : null,
      items: this.lines().map((l) => ({ stock_id: l.stock.id, quantity: l.quantity })),
      signature: this.signature(),
      photo: this.photo(),
      note: this.note.trim() || null,
      ...location,
      device_time: new Date().toISOString(),
    };

    this.http
      .post<{ message: string; delivery: Delivery }>(`${API_URL}/deliveries`, body, {
        headers: { 'Idempotency-Key': this.idempotencyKey },
      })
      .subscribe({
        next: (r) => {
          this.saving.set(false);
          this.done.set(r.delivery);
          this.toast.success(`${r.delivery.number} kaydedildi.`);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        error: (e) => {
          this.saving.set(false);
          this.error.set(errorMessage(e));
          // Stok başkası tarafından değişmiş olabilir
          if (e.status === 422) this.loadRows();
        },
      });
  }

  /** Aynı hastane ve araçla yeni belge */
  protected startNew(): void {
    this.done.set(null);
    this.idempotencyKey = newKey();
    this.cart.set(new Map());
    this.signature.set(null);
    this.photo.set(null);
    this.note = '';
    this.scanMessage.set(null);
    this.error.set('');
    this.loadRows();
  }
}
