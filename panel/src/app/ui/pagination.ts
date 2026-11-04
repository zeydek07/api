import { Component, computed, input, output } from '@angular/core';
import { Page } from '../core/models';

@Component({
  selector: 'app-pagination',
  template: `
    @if (page(); as p) {
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-muted">
        <div class="flex items-center gap-2">
          <span>Sayfa başına</span>
          <select
            class="select h-8 w-20"
            [value]="p.per_page"
            (change)="sizeChange.emit(+$any($event.target).value)"
            aria-label="Sayfa başına kayıt"
          >
            @for (s of sizes(); track s) {
              <option [value]="s">{{ s }}</option>
            }
          </select>
        </div>
        <div class="flex items-center gap-3">
          <span class="tabular-nums">{{ range() }}</span>
          <div class="flex gap-1">
            <button
              class="btn-icon"
              [disabled]="p.current_page <= 1"
              (click)="pageChange.emit(p.current_page - 1)"
              aria-label="Önceki sayfa"
            >
              <span class="icon">chevron_left</span>
            </button>
            <button
              class="btn-icon"
              [disabled]="p.current_page >= p.last_page"
              (click)="pageChange.emit(p.current_page + 1)"
              aria-label="Sonraki sayfa"
            >
              <span class="icon">chevron_right</span>
            </button>
          </div>
        </div>
      </div>
    }
  `,
})
export class Pagination {
  readonly page = input<Page<unknown> | null>(null);
  readonly sizes = input([25, 50, 100]);
  /** 1'den başlayan sayfa numarası */
  readonly pageChange = output<number>();
  readonly sizeChange = output<number>();

  protected readonly range = computed(() => {
    const p = this.page();
    if (!p || p.total === 0) return '0 kayıt';
    const start = (p.current_page - 1) * p.per_page + 1;
    const end = Math.min(start + p.per_page - 1, p.total);
    return `${start}–${end} / ${p.total}`;
  });
}
