import { Component, computed, input, model } from '@angular/core';
import { PermissionGroup } from '../core/models';

/**
 * Sayfa sayfa yetki seçimi. Her satır bir sayfa, her kutu bir işlem.
 * - locked: rolden gelen yetkiler (işaretli ve kilitli)
 * - allowed: verilebilecek yetkiler (kullanıcının kendisinde olanlar); null = hepsi
 */
@Component({
  selector: 'app-permission-matrix',
  template: `
    <div class="overflow-hidden rounded-xl border border-line">
      @for (g of groups(); track g.page; let odd = $odd) {
        <div
          class="grid gap-x-4 gap-y-2 border-t border-line px-4 py-3 first:border-t-0 sm:grid-cols-[200px_1fr]"
          [class.bg-surface-2]="odd"
        >
          <label class="check font-medium text-ink">
            <input
              type="checkbox"
              [checked]="allChecked(g)"
              [indeterminate]="someChecked(g) && !allChecked(g)"
              [disabled]="disabled() || groupLocked(g)"
              (change)="toggleGroup(g, $any($event.target).checked)"
            />
            {{ g.label }}
          </label>
          <div class="flex flex-wrap gap-x-5 gap-y-2">
            @for (p of g.permissions; track p.key) {
              <label class="check" [class.opacity-60]="!isAllowed(p.key) && !isLocked(p.key)">
                <input
                  type="checkbox"
                  [checked]="isChecked(p.key)"
                  [disabled]="disabled() || isLocked(p.key) || !isAllowed(p.key)"
                  (change)="toggle(p.key, $any($event.target).checked)"
                />
                {{ actionLabel(p.label) }}
                @if (isLocked(p.key) && lockedLabel()) {
                  <span class="badge badge-primary">{{ lockedLabel() }}</span>
                }
              </label>
            }
          </div>
        </div>
      }
    </div>
  `,
})
export class PermissionMatrix {
  readonly groups = input.required<PermissionGroup[]>();
  readonly selected = model<string[]>([]);
  readonly locked = input<string[]>([]);
  readonly lockedLabel = input('');
  readonly allowed = input<string[] | null>(null);
  readonly disabled = input(false);

  private readonly selectedSet = computed(() => new Set(this.selected()));
  private readonly lockedSet = computed(() => new Set(this.locked()));
  private readonly allowedSet = computed(() => {
    const a = this.allowed();
    return a ? new Set(a) : null;
  });

  protected isChecked(key: string): boolean {
    return this.selectedSet().has(key) || this.lockedSet().has(key);
  }

  protected isLocked(key: string): boolean {
    return this.lockedSet().has(key);
  }

  protected isAllowed(key: string): boolean {
    const set = this.allowedSet();
    return !set || set.has(key);
  }

  protected allChecked(g: PermissionGroup): boolean {
    return g.permissions.every((p) => this.isChecked(p.key));
  }

  protected someChecked(g: PermissionGroup): boolean {
    return g.permissions.some((p) => this.isChecked(p.key));
  }

  protected groupLocked(g: PermissionGroup): boolean {
    return g.permissions.every((p) => this.isLocked(p.key) || !this.isAllowed(p.key));
  }

  protected toggle(key: string, checked: boolean): void {
    const set = new Set(this.selected());
    if (checked) set.add(key);
    else set.delete(key);
    this.selected.set([...set]);
  }

  protected toggleGroup(g: PermissionGroup, checked: boolean): void {
    const set = new Set(this.selected());
    for (const p of g.permissions) {
      if (this.isLocked(p.key) || !this.isAllowed(p.key)) continue;
      if (checked) set.add(p.key);
      else set.delete(p.key);
    }
    this.selected.set([...set]);
  }

  /** "Ürünler - Ekle" -> "Ekle" */
  protected actionLabel(label: string): string {
    const i = label.indexOf(' - ');
    return i >= 0 ? label.slice(i + 3) : label;
  }
}
