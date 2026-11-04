import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { API_URL, errorMessage } from '../../core/api';
import { Product } from '../../core/models';
import { ModalFrame } from '../../ui/modal-frame';
import { ToastService } from '../../ui/toast';

@Component({
  imports: [ReactiveFormsModule, ModalFrame],
  template: `
    <form [formGroup]="form" (ngSubmit)="save()">
      <app-modal [title]="data.product ? 'Ürünü düzenle' : 'Yeni ürün'" subtitle="Ürün bilgileri tüm depolarda ortaktır.">
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="label" for="p-name">Ürün adı *</label>
            <input id="p-name" class="input" formControlName="name" />
          </div>
          <div>
            <label class="label" for="p-code">Ürün kodu *</label>
            <input id="p-code" class="input font-mono" formControlName="code" />
          </div>
          <div>
            <label class="label" for="p-barcode">Barkod</label>
            <input id="p-barcode" class="input font-mono" formControlName="barcode" />
          </div>
          <div>
            <label class="label" for="p-cat">Kategori</label>
            <input id="p-cat" class="input" formControlName="category" list="p-cat-list" />
            <datalist id="p-cat-list">
              @for (c of data.categories; track c) {
                <option [value]="c"></option>
              }
            </datalist>
          </div>
          <div>
            <label class="label" for="p-brand">Marka</label>
            <input id="p-brand" class="input" formControlName="brand" />
          </div>
          <div>
            <label class="label" for="p-unit">Birim *</label>
            <input id="p-unit" class="input" formControlName="unit" list="p-unit-list" />
            <datalist id="p-unit-list">
              <option value="adet"></option>
              <option value="kutu"></option>
              <option value="paket"></option>
              <option value="şişe"></option>
              <option value="ml"></option>
            </datalist>
          </div>
          <div>
            <label class="label" for="p-min">Minimum stok seviyesi</label>
            <input id="p-min" class="input" type="number" min="0" formControlName="min_stock_level" />
            <p class="hint">Toplam stok bunun altına inince uyarı verilir</p>
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="p-desc">Açıklama</label>
            <textarea id="p-desc" class="textarea" rows="3" formControlName="description"></textarea>
          </div>
          <label class="check sm:col-span-2">
            <input type="checkbox" class="switch" formControlName="is_active" />
            Aktif <span class="text-muted">(sahada kullanılabilir)</span>
          </label>
          @if (error()) {
            <p class="form-error sm:col-span-2">{{ error() }}</p>
          }
        </div>
        <div modal-footer class="flex gap-2">
          <button type="button" class="btn btn-secondary" (click)="ref.close()">Vazgeç</button>
          <button type="submit" class="btn btn-primary" [disabled]="form.invalid || saving()">Kaydet</button>
        </div>
      </app-modal>
    </form>
  `,
})
export class ProductDialog {
  protected readonly data = inject<{ product?: Product; categories: string[] }>(DIALOG_DATA);
  private readonly http = inject(HttpClient);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly toast = inject(ToastService);

  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: [this.data.product?.name ?? '', Validators.required],
    code: [this.data.product?.code ?? '', Validators.required],
    barcode: [this.data.product?.barcode ?? ''],
    category: [this.data.product?.category ?? ''],
    brand: [this.data.product?.brand ?? ''],
    unit: [this.data.product?.unit ?? 'adet', Validators.required],
    min_stock_level: [this.data.product?.min_stock_level ?? 0, [Validators.required, Validators.min(0)]],
    description: [this.data.product?.description ?? ''],
    is_active: [this.data.product?.is_active ?? true],
  });

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    const raw = this.form.getRawValue();
    // Boş metinleri null gönder (benzersiz barkod kontrolü boş metne takılmasın)
    const body = {
      ...raw,
      barcode: raw.barcode || null,
      category: raw.category || null,
      brand: raw.brand || null,
      description: raw.description || null,
    };
    const id = this.data.product?.id;
    const req = id
      ? this.http.put<Product>(`${API_URL}/products/${id}`, body)
      : this.http.post<Product>(`${API_URL}/products`, body);

    req.subscribe({
      next: () => {
        this.toast.success('Ürün kaydedildi.');
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
