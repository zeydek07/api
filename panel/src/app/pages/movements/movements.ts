import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, OnInit, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { debounceTime } from 'rxjs';
import { API_URL, qty, toParams } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { Hospital, MOVEMENT_LABELS, Movement, MovementType, Page, Product, User, Warehouse } from '../../core/models';
import { Pagination } from '../../ui/pagination';
import { ProductPicker } from '../../ui/product-picker';

const TYPE_STYLE: Record<MovementType, { badge: string; icon: string }> = {
  in: { badge: 'badge-ok', icon: 'south_west' },
  transfer_in: { badge: 'badge-ok', icon: 'login' },
  transfer_out: { badge: 'badge-warn', icon: 'logout' },
  usage: { badge: 'badge-primary', icon: 'medical_services' },
  adjustment: { badge: '', icon: 'fact_check' },
};

/** Kim, ne zaman, hangi depodan, ne kullandı */
@Component({
  imports: [DatePipe, ReactiveFormsModule, Pagination, ProductPicker],
  templateUrl: './movements.html',
})
export class MovementsPage implements OnInit {
  /** /hareketler?hastane=3 ile ön filtre */
  readonly hastane = input<string>();

  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  protected readonly page = signal<Page<Movement> | null>(null);
  protected readonly users = signal<User[]>([]);
  protected readonly warehouses = signal<Warehouse[]>([]);
  protected readonly hospitals = signal<Hospital[]>([]);
  protected readonly loading = signal(false);
  protected readonly typeLabels: Record<string, string> = MOVEMENT_LABELS;
  protected readonly typeStyle: Record<string, { badge: string; icon: string }> = TYPE_STYLE;
  protected readonly types = Object.keys(MOVEMENT_LABELS) as MovementType[];
  protected readonly qty = qty;
  protected readonly product = signal<Product | null>(null);

  protected readonly filters = inject(FormBuilder).nonNullable.group({
    type: ['usage' as MovementType | ''],
    user_id: [''],
    warehouse_id: [''],
    hospital_id: [''],
    from: [''],
    to: [''],
    reference: [''],
  });
  private pageNo = 1;
  private perPage = 50;

  constructor() {
    this.filters.valueChanges.pipe(debounceTime(300), takeUntilDestroyed()).subscribe(() => this.reset());
    if (this.auth.can('users.view')) {
      this.http
        .get<Page<User>>(`${API_URL}/users`, { params: { per_page: 100 } })
        .subscribe((p) => this.users.set(p.data));
    }
    if (this.auth.can('warehouses.view')) {
      this.http.get<Warehouse[]>(`${API_URL}/warehouses`).subscribe((w) => this.warehouses.set(w));
    }
    if (this.auth.can('hospitals.view')) {
      this.http
        .get<Page<Hospital>>(`${API_URL}/hospitals`, { params: { per_page: 100 } })
        .subscribe((p) => this.hospitals.set(p.data));
    }
  }

  ngOnInit(): void {
    if (this.hastane()) this.filters.patchValue({ hospital_id: this.hastane()!, type: 'usage' }, { emitEvent: false });
    this.load();
  }

  protected onProduct(p: Product | null): void {
    this.product.set(p);
    this.reset();
  }

  protected clear(): void {
    this.filters.reset({ type: '', user_id: '', warehouse_id: '', hospital_id: '', from: '', to: '', reference: '' });
  }

  private reset(): void {
    this.pageNo = 1;
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      ...this.filters.getRawValue(),
      product_id: this.product()?.id,
      page: this.pageNo,
      per_page: this.perPage,
    });
    this.http.get<Page<Movement>>(`${API_URL}/movements`, { params }).subscribe({
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

  protected positive(m: Movement): boolean {
    return Number(m.quantity) > 0;
  }

  /** "SN 123 · LOT A41 · SKT 31.12.2027" */
  protected traceText(m: Movement): string {
    const skt = m.expiry_date ? m.expiry_date.slice(0, 10).split('-').reverse().join('.') : '';
    return [m.serial_number && 'SN ' + m.serial_number, m.lot_number && 'LOT ' + m.lot_number, skt && 'SKT ' + skt]
      .filter((x) => !!x)
      .join(' · ');
  }

  protected mapUrl(m: Movement): string | null {
    return m.latitude && m.longitude ? `https://www.google.com/maps?q=${m.latitude},${m.longitude}` : null;
  }
}
