import { Dialog } from '@angular/cdk/dialog';
import { CdkMenu, CdkMenuItem, CdkMenuTrigger } from '@angular/cdk/menu';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { debounceTime, merge } from 'rxjs';
import { API_URL, errorMessage, qty, toParams } from '../../core/api';
import { CanDirective } from '../../core/can.directive';
import { Page, Product } from '../../core/models';
import { confirm } from '../../ui/confirm';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { ToastService } from '../../ui/toast';
import { ProductDetailDialog } from './product-detail';
import { ProductDialog } from './product-dialog';

@Component({
  imports: [ReactiveFormsModule, CanDirective, Pagination, CdkMenuTrigger, CdkMenu, CdkMenuItem],
  templateUrl: './products.html',
})
export class ProductsPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);

  protected readonly page = signal<Page<Product> | null>(null);
  protected readonly categories = signal<string[]>([]);
  protected readonly loading = signal(false);
  protected readonly qty = qty;

  protected readonly q = new FormControl('', { nonNullable: true });
  protected readonly category = new FormControl('', { nonNullable: true });
  protected readonly active = new FormControl<'' | '1' | '0'>('', { nonNullable: true });
  private pageNo = 1;
  private perPage = 25;

  constructor() {
    merge(this.q.valueChanges.pipe(debounceTime(300)), this.category.valueChanges, this.active.valueChanges)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        this.pageNo = 1;
        this.load();
      });
    this.load();
    this.loadCategories();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      q: this.q.value,
      category: this.category.value,
      is_active: this.active.value,
      page: this.pageNo,
      per_page: this.perPage,
    });
    this.http.get<Page<Product>>(`${API_URL}/products`, { params }).subscribe({
      next: (p) => {
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private loadCategories(): void {
    this.http.get<string[]>(`${API_URL}/products/categories`).subscribe((c) => this.categories.set(c));
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

  protected hasFilters(): boolean {
    return !!(this.q.value || this.category.value || this.active.value);
  }

  protected clearFilters(): void {
    this.q.setValue('', { emitEvent: false });
    this.category.setValue('', { emitEvent: false });
    this.active.setValue('');
  }

  protected isLow(p: Product): boolean {
    return p.min_stock_level > 0 && Number(p.total_stock ?? 0) <= p.min_stock_level;
  }

  protected open(product?: Product): void {
    openModal<boolean>(this.dialog, ProductDialog, { product, categories: this.categories() }, 'lg').subscribe(
      (saved) => {
        if (saved) {
          this.load();
          this.loadCategories();
        }
      },
    );
  }

  protected detail(product: Product): void {
    openModal(this.dialog, ProductDetailDialog, product.id, 'lg');
  }

  protected remove(product: Product): void {
    confirm(this.dialog, {
      title: 'Ürünü sil',
      message: `"${product.name}" silinsin mi? Geçmiş hareket kayıtları korunur.`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/products/${product.id}`).subscribe({
        next: () => {
          this.toast.success('Ürün silindi.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }
}
