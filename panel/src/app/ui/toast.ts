import { Component, Injectable, inject, signal } from '@angular/core';

export type ToastKind = 'success' | 'error' | 'info';

interface Toast {
  id: number;
  kind: ToastKind;
  text: string;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  readonly items = signal<Toast[]>([]);
  private seq = 0;

  success(text: string): void {
    this.push('success', text);
  }

  error(text: string): void {
    this.push('error', text, 7000);
  }

  info(text: string): void {
    this.push('info', text);
  }

  dismiss(id: number): void {
    this.items.update((list) => list.filter((t) => t.id !== id));
  }

  private push(kind: ToastKind, text: string, ms = 4000): void {
    const id = ++this.seq;
    this.items.update((list) => [...list.slice(-3), { id, kind, text }]);
    setTimeout(() => this.dismiss(id), ms);
  }
}

@Component({
  selector: 'app-toaster',
  template: `
    <div class="pointer-events-none fixed inset-x-4 bottom-4 z-[2000] flex flex-col items-end gap-2 sm:left-auto sm:w-96">
      @for (t of toast.items(); track t.id) {
        <div
          class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border border-line bg-surface p-3.5 shadow-lg shadow-black/10"
          [attr.role]="t.kind === 'error' ? 'alert' : 'status'"
        >
          <span
            class="icon mt-px"
            [class.text-ok]="t.kind === 'success'"
            [class.text-danger]="t.kind === 'error'"
            [class.text-primary]="t.kind === 'info'"
            aria-hidden="true"
            >{{ t.kind === 'success' ? 'check_circle' : t.kind === 'error' ? 'error' : 'info' }}</span
          >
          <p class="flex-1 whitespace-pre-line text-sm text-ink">{{ t.text }}</p>
          <button class="btn-icon -m-1 size-7" (click)="toast.dismiss(t.id)" aria-label="Kapat">
            <span class="icon text-[18px]">close</span>
          </button>
        </div>
      }
    </div>
  `,
})
export class Toaster {
  protected readonly toast = inject(ToastService);
}
