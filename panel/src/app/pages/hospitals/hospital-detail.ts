import { Dialog } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { API_URL, qty, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { CanDirective } from '../../core/can.directive';
import { ConsignmentLocation, HOSPITAL_LABELS, Hospital, Movement } from '../../core/models';
import { HospitalConsignmentTab, HospitalContactsTab, HospitalDeliveriesTab, HospitalLocationsTab } from './hospital-tabs';
import { openModal } from '../../ui/modal';
import { HospitalDialog } from './hospital-dialog';

interface Detail {
  hospital: Hospital;
  from: string;
  to: string;
  products: { id: number; name: string; code: string; unit: string; total_quantity: string; line_count: number }[];
  representatives: { id: number; name: string; delivery_count: number; last_at: string }[];
  latest: Movement[];
}

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Hastane kartı: bilgiler + bu hastaneye kim, ne zaman, hangi ürünü verdi */
@Component({
  imports: [
    DatePipe,
    FormsModule,
    RouterLink,
    CanDirective,
    HospitalConsignmentTab,
    HospitalDeliveriesTab,
    HospitalContactsTab,
    HospitalLocationsTab,
  ],
  templateUrl: './hospital-detail.html',
})
export class HospitalDetailPage {
  /** Rota parametresi :id */
  readonly id = input.required<string>();

  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  protected readonly auth = inject(AuthService);
  protected readonly typeLabels: Record<string, string> = HOSPITAL_LABELS;
  protected readonly qty = qty;

  protected readonly data = signal<Detail | null>(null);

  protected readonly tabs = [
    { key: 'konsinye', label: 'Konsinye stok', icon: 'inventory_2' },
    { key: 'teslimatlar', label: 'Teslimatlar', icon: 'receipt_long' },
    { key: 'kisiler', label: 'Hastane personeli', icon: 'group' },
    { key: 'lokasyonlar', label: 'Lokasyonlar', icon: 'shelves' },
    { key: 'kullanim', label: 'Kullanım geçmişi', icon: 'history' },
  ];
  protected readonly tab = signal('konsinye');
  protected readonly allLocations = signal<ConsignmentLocation[]>([]);
  protected readonly locations = computed(() => this.allLocations().filter((l) => l.is_active));

  /** Lokasyon sekmesinde değişiklik olunca listeyi yenile */
  protected readonly reloadLocations = () => this.loadLocations();
  protected readonly loading = signal(false);
  protected from = isoDate(new Date(Date.now() - 89 * 86400000));
  protected to = isoDate(new Date());

  protected readonly deliveryCount = computed(
    () => new Set((this.data()?.latest ?? []).map((m) => m.batch_uuid)).size,
  );
  protected readonly maxQty = computed(() =>
    Math.max(1, ...(this.data()?.products ?? []).map((p) => Number(p.total_quantity))),
  );

  constructor() {
    effect(() => {
      this.id();
      this.load();
      this.loadLocations();
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.http
      .get<Detail>(`${API_URL}/hospitals/${this.id()}`, { params: toParams({ from: this.from, to: this.to }) })
      .subscribe({
        next: (d) => {
          this.data.set(d);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  private loadLocations(): void {
    this.http
      .get<ConsignmentLocation[]>(`${API_URL}/hospitals/${this.id()}/locations`)
      .subscribe((l) => this.allLocations.set(l));
  }

  protected edit(): void {
    const h = this.data()?.hospital;
    if (!h) return;
    openModal<boolean>(this.dialog, HospitalDialog, { hospital: h }, 'lg').subscribe(
      (saved) => saved && this.load(),
    );
  }

  protected pct(v: string): number {
    return (Number(v) / this.maxQty()) * 100;
  }
}
