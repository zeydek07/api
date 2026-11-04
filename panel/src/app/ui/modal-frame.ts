import { DialogRef } from '@angular/cdk/dialog';
import { Component, inject, input } from '@angular/core';

/**
 * Tüm pencerelerin ortak çerçevesi: başlık, kaydırılabilir gövde, alt butonlar.
 * <app-modal title="..."> gövde <div modal-footer> butonlar </div> </app-modal>
 */
@Component({
  selector: 'app-modal',
  template: `
    <div class="flex max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl">
      <header class="flex items-start gap-3 border-b border-line px-6 py-4">
        <div class="flex-1">
          <h2 class="text-base font-semibold text-ink">{{ title() }}</h2>
          @if (subtitle()) {
            <p class="mt-0.5 text-sm text-muted">{{ subtitle() }}</p>
          }
        </div>
        <button type="button" class="btn-icon -mr-2" (click)="ref.close()" aria-label="Kapat">
          <span class="icon">close</span>
        </button>
      </header>
      <div class="flex-1 overflow-y-auto px-6 py-5">
        <ng-content />
      </div>
      <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-2 px-6 py-3.5">
        <ng-content select="[modal-footer]" />
      </footer>
    </div>
  `,
})
export class ModalFrame {
  readonly title = input.required<string>();
  readonly subtitle = input<string>();
  protected readonly ref = inject(DialogRef);
}
