import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { HttpClient } from '@angular/common/http';
import { Component, inject, input, model, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime, switchMap } from 'rxjs';
import { API_URL } from '../core/api';
import { Page, Product } from '../core/models';

/** Ad, kod veya barkodla aranıp seçilen ürün alanı */
@Component({
  selector: 'app-product-picker',
  imports: [CdkConnectedOverlay, CdkOverlayOrigin],
  template: `
    @if (label()) {
      <label class="label" [attr.for]="id">{{ label() }}</label>
    }
    <div class="relative" cdkOverlayOrigin #origin="cdkOverlayOrigin">
      <span class="icon pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-muted">search</span>
      <input
        [id]="id"
        class="input pl-9"
        [class.pr-8]="product()"
        [value]="text()"
        [placeholder]="placeholder()"
        autocomplete="off"
        role="combobox"
        [attr.aria-expanded]="open()"
        (input)="onInput($any($event.target).value)"
        (focus)="onFocus()"
        (keydown)="onKey($event)"
      />
      @if (product()) {
        <button
          type="button"
          class="btn-icon absolute right-1 top-1/2 size-7 -translate-y-1/2"
          (click)="clear()"
          aria-label="Seçimi temizle"
        >
          <span class="icon text-[16px]">close</span>
        </button>
      }
    </div>
    <ng-template
      cdkConnectedOverlay
      [cdkConnectedOverlayOrigin]="origin"
      [cdkConnectedOverlayOpen]="open() && (options().length > 0 || searched())"
      [cdkConnectedOverlayWidth]="origin.elementRef.nativeElement.offsetWidth"
      [cdkConnectedOverlayPositions]="positions"
      (overlayOutsideClick)="open.set(false)"
      (detach)="open.set(false)"
    >
      <ul class="menu max-h-64 w-full overflow-y-auto" role="listbox">
        @for (p of options(); track p.id; let i = $index) {
          <li
            role="option"
            class="menu-item flex-col items-start gap-0"
            [class.bg-surface-2]="i === active()"
            [attr.aria-selected]="i === active()"
            (mousedown)="$event.preventDefault(); select(p)"
            (mouseenter)="active.set(i)"
          >
            <span class="text-ink">{{ p.name }}</span>
            <span class="text-xs text-muted">{{ p.code }}{{ p.barcode ? ' · ' + p.barcode : '' }} · {{ p.unit }}</span>
          </li>
        } @empty {
          <li class="px-3 py-2 text-sm text-muted">Ürün bulunamadı</li>
        }
      </ul>
    </ng-template>
  `,
})
export class ProductPicker {
  private static seq = 0;
  protected readonly id = `pp-${++ProductPicker.seq}`;

  readonly label = input('');
  readonly placeholder = input('Ad, kod veya barkod');
  readonly product = model<Product | null>(null);

  protected readonly text = signal('');
  protected readonly options = signal<Product[]>([]);
  protected readonly open = signal(false);
  protected readonly searched = signal(false);
  protected readonly active = signal(0);
  protected readonly positions: ConnectedPosition[] = [
    { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 4 },
    { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -4 },
  ];

  private readonly query$ = new Subject<string>();
  private readonly http = inject(HttpClient);

  constructor() {
    this.query$
      .pipe(
        debounceTime(200),
        switchMap((q) =>
          this.http.get<Page<Product>>(`${API_URL}/products`, { params: { q, is_active: 1, per_page: 15 } }),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((p) => {
        this.options.set(p.data);
        this.searched.set(true);
        this.active.set(0);
      });
  }

  protected onInput(value: string): void {
    this.text.set(value);
    if (this.product()) this.product.set(null);
    this.open.set(true);
    this.query$.next(value);
  }

  protected onFocus(): void {
    if (!this.product()) {
      this.open.set(true);
      this.query$.next(this.text());
    }
  }

  protected onKey(e: KeyboardEvent): void {
    const n = this.options().length;
    if (e.key === 'ArrowDown' && n) {
      e.preventDefault();
      this.open.set(true);
      this.active.update((i) => (i + 1) % n);
    } else if (e.key === 'ArrowUp' && n) {
      e.preventDefault();
      this.active.update((i) => (i - 1 + n) % n);
    } else if (e.key === 'Enter' && this.open() && n) {
      e.preventDefault();
      this.select(this.options()[this.active()]);
    } else if (e.key === 'Escape') {
      this.open.set(false);
    }
  }

  protected select(p: Product): void {
    this.product.set(p);
    this.text.set(p.name);
    this.open.set(false);
  }

  protected clear(): void {
    this.product.set(null);
    this.text.set('');
    this.options.set([]);
    this.searched.set(false);
  }
}
