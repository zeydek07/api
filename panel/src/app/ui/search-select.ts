import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { Component, ElementRef, computed, forwardRef, input, signal, viewChild } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

export interface SelectOption {
  value: string;
  label: string;
  /** Solda küçük gösterilen ek bilgi (ör. plaka kodu) */
  hint?: string;
}

/** Türkçe karakterleri sadeleştirir: "Çanakkale" ve "canakkale" eşleşir, "İzmir" ve "izmir" eşleşir. */
function normalize(s: string): string {
  return s
    .toLocaleLowerCase('tr')
    .replace(/[çğıiöşü]/g, (c) => ({ ç: 'c', ğ: 'g', ı: 'i', i: 'i', ö: 'o', ş: 's', ü: 'u' })[c] ?? c)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');
}

/**
 * Aramalı açılır liste. Her zaman alanın ALTINA açılır; aşağıda yer azsa
 * yukarı taşmak yerine kısalır ve kendi içinde kayar.
 * Reactive forms ile kullanılır: <app-search-select formControlName="city" [options]="..." />
 */
@Component({
  selector: 'app-search-select',
  imports: [CdkConnectedOverlay, CdkOverlayOrigin],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => SearchSelect), multi: true }],
  template: `
    <div class="relative" cdkOverlayOrigin #origin="cdkOverlayOrigin">
      <button
        #trigger
        type="button"
        [id]="inputId()"
        class="select flex items-center text-left"
        [class.select-open]="open()"
        [class.pr-14]="clearable() && value()"
        [disabled]="disabled()"
        role="combobox"
        aria-haspopup="listbox"
        [attr.aria-expanded]="open()"
        [attr.aria-label]="ariaLabel() || null"
        (click)="toggle()"
        (keydown)="onTriggerKey($event)"
      >
        <span class="block min-w-0 flex-1 truncate" [class.text-muted]="!selected()">
          @if (selected(); as s) {
            @if (s.hint) {
              <span class="mr-1.5 font-mono text-xs text-muted">{{ s.hint }}</span>
            }
            {{ s.label }}
          } @else {
            {{ placeholder() }}
          }
        </span>
      </button>
      @if (clearable() && value() && !disabled()) {
        <button
          type="button"
          class="absolute right-7 top-1/2 grid size-6 -translate-y-1/2 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-ink"
          (click)="clear()"
          [attr.aria-label]="'Seçimi temizle'"
        >
          <span class="icon text-[16px]">close</span>
        </button>
      }
    </div>

    <ng-template
      cdkConnectedOverlay
      [cdkConnectedOverlayOrigin]="origin"
      [cdkConnectedOverlayOpen]="open()"
      [cdkConnectedOverlayPositions]="positions"
      [cdkConnectedOverlayWidth]="panelWidth(origin.elementRef.nativeElement)"
      [cdkConnectedOverlayFlexibleDimensions]="true"
      [cdkConnectedOverlayGrowAfterOpen]="true"
      [cdkConnectedOverlayViewportMargin]="12"
      cdkConnectedOverlayPanelClass="search-select-pane"
      (overlayOutsideClick)="close(false)"
      (detach)="close(false)"
      (attach)="onOpened()"
    >
      <div class="flex max-h-80 min-h-0 w-full flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-xl shadow-black/10">
        <div class="relative shrink-0 border-b border-line p-2">
          <span class="icon pointer-events-none absolute left-4.5 top-1/2 -translate-y-1/2 text-[18px] text-muted">search</span>
          <input
            #search
            class="h-9 w-full rounded-lg bg-surface-2 pl-9 pr-3 text-sm text-ink placeholder:text-muted/70 focus:outline-none focus:ring-2 focus:ring-[var(--ring)]"
            [placeholder]="searchPlaceholder()"
            [value]="query()"
            (input)="onQuery($any($event.target).value)"
            (keydown)="onSearchKey($event)"
            aria-label="Listede ara"
            autocomplete="off"
          />
        </div>
        <ul #list class="min-h-0 flex-1 overflow-y-auto p-1.5" role="listbox">
          @if (clearable() && !query()) {
            <li
              role="option"
              class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-muted"
              [class.bg-surface-2]="active() === -1"
              [attr.aria-selected]="!value()"
              (mouseenter)="active.set(-1)"
              (mousedown)="$event.preventDefault(); pick(null)"
            >
              @if (hasHints()) {
                <span class="w-6"></span>
              }
              <span class="flex-1">{{ placeholder() }}</span>
              @if (!value()) {
                <span class="icon text-[18px] text-primary">check</span>
              }
            </li>
          }
          @for (o of filtered(); track o.value; let i = $index) {
            <li
              role="option"
              [attr.data-i]="i"
              class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm"
              [class.bg-surface-2]="active() === i"
              [class.font-medium]="o.value === value()"
              [class.text-primary-ink]="o.value === value()"
              [class.text-ink]="o.value !== value()"
              [attr.aria-selected]="o.value === value()"
              (mouseenter)="active.set(i)"
              (mousedown)="$event.preventDefault(); pick(o.value)"
            >
              @if (hasHints()) {
                <span class="w-6 shrink-0 font-mono text-xs text-muted">{{ o.hint }}</span>
              }
              <span class="min-w-0 flex-1 truncate">{{ o.label }}</span>
              @if (o.value === value()) {
                <span class="icon text-[18px] text-primary">check</span>
              }
            </li>
          } @empty {
            <li class="px-3 py-6 text-center text-sm text-muted">“{{ query() }}” için sonuç yok</li>
          }
        </ul>
      </div>
    </ng-template>
  `,
})
export class SearchSelect implements ControlValueAccessor {
  readonly options = input.required<SelectOption[]>();
  readonly placeholder = input('Seçin');
  readonly searchPlaceholder = input('Ara…');
  /** Boş seçeneği (placeholder) listede göster, seçimi temizlemeye izin ver */
  readonly clearable = input(false);
  readonly inputId = input<string>();
  readonly ariaLabel = input<string>();

  protected readonly value = signal<string | null>(null);
  protected readonly disabled = signal(false);
  protected readonly open = signal(false);
  protected readonly query = signal('');
  protected readonly active = signal(0);

  private readonly trigger = viewChild.required<ElementRef<HTMLButtonElement>>('trigger');
  private readonly search = viewChild<ElementRef<HTMLInputElement>>('search');
  private readonly list = viewChild<ElementRef<HTMLUListElement>>('list');

  /** Yalnızca alttaki konumlar: liste hiçbir zaman alanın üstüne açılmaz */
  protected readonly positions: ConnectedPosition[] = [
    { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  ];

  /** Seçeneklerde kod/ek bilgi varsa sol sütunu göster */
  protected readonly hasHints = computed(() => this.options().some((o) => !!o.hint));

  protected readonly selected = computed(() => this.options().find((o) => o.value === this.value()) ?? null);

  protected readonly filtered = computed(() => {
    const q = normalize(this.query().trim());
    if (!q) return this.options();
    const starts: SelectOption[] = [];
    const contains: SelectOption[] = [];
    for (const o of this.options()) {
      const label = normalize(o.label);
      if (label.startsWith(q) || o.hint === q) starts.push(o);
      else if (label.includes(q)) contains.push(o);
    }
    return [...starts, ...contains];
  });

  private onChange: (v: string | null) => void = () => {};
  private onTouched: () => void = () => {};

  writeValue(v: string | null): void {
    this.value.set(v || null);
  }
  registerOnChange(fn: (v: string | null) => void): void {
    this.onChange = fn;
  }
  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }
  setDisabledState(d: boolean): void {
    this.disabled.set(d);
  }

  /** Panel alan kadar geniş açılır; dar alanlarda arama kutusu sığsın diye en az 260px */
  protected panelWidth(el: HTMLElement): number {
    return Math.max(el.offsetWidth, 260);
  }

  protected toggle(): void {
    if (this.open()) this.close(true);
    else this.openPanel();
  }

  private openPanel(): void {
    if (this.disabled()) return;
    this.query.set('');
    const idx = this.options().findIndex((o) => o.value === this.value());
    this.active.set(idx >= 0 ? idx : this.clearable() ? -1 : 0);
    this.open.set(true);
  }

  protected onOpened(): void {
    // Arama kutusuna odaklan ve seçili öğeyi görünür yap
    setTimeout(() => {
      this.search()?.nativeElement.focus();
      this.scrollActive('center');
    });
  }

  protected close(refocus: boolean): void {
    if (!this.open()) return;
    this.open.set(false);
    this.onTouched();
    if (refocus) this.trigger().nativeElement.focus();
  }

  protected pick(v: string | null): void {
    this.value.set(v);
    this.onChange(v);
    this.close(true);
  }

  protected clear(): void {
    this.pick(null);
  }

  protected onQuery(q: string): void {
    this.query.set(q);
    this.active.set(0);
    this.list()?.nativeElement.scrollTo({ top: 0 });
  }

  protected onTriggerKey(e: KeyboardEvent): void {
    // Liste açıkken odak arama kutusuna geçmeden basılan tuşlar kaybolmasın
    if (this.open()) {
      if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        this.onQuery(this.query() + e.key);
        this.search()?.nativeElement.focus();
      } else {
        this.onSearchKey(e);
      }
      return;
    }
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
      e.preventDefault();
      this.openPanel();
    } else if (e.key.length === 1 && /\S/.test(e.key)) {
      // Kapalıyken harfe basınca aramayı o harfle başlat
      this.openPanel();
      this.query.set(e.key);
      this.active.set(0);
    }
  }

  protected onSearchKey(e: KeyboardEvent): void {
    const n = this.filtered().length;
    const min = this.clearable() && !this.query() ? -1 : 0;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      this.active.update((i) => Math.min(n - 1, i + 1));
      this.scrollActive('nearest');
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      this.active.update((i) => Math.max(min, i - 1));
      this.scrollActive('nearest');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const i = this.active();
      if (i === -1) this.pick(null);
      else if (this.filtered()[i]) this.pick(this.filtered()[i].value);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      this.close(true);
    } else if (e.key === 'Tab') {
      this.close(false);
    }
  }

  private scrollActive(block: ScrollLogicalPosition): void {
    setTimeout(() =>
      this.list()?.nativeElement.querySelector(`[data-i="${this.active()}"]`)?.scrollIntoView({ block }),
    );
  }
}
