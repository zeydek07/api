import { DIALOG_DATA, Dialog, DialogRef } from '@angular/cdk/dialog';
import { Component, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { openModal } from './modal';

interface ConfirmData {
  title: string;
  message: string;
  confirmText?: string;
  danger?: boolean;
}

@Component({
  template: `
    <div class="rounded-2xl border border-line bg-surface p-6 shadow-2xl">
      <div class="flex gap-4">
        <div
          class="grid size-10 shrink-0 place-items-center rounded-full"
          [class]="data.danger ? 'bg-danger-soft text-danger' : 'bg-primary-soft text-primary-ink'"
        >
          <span class="icon">{{ data.danger ? 'warning' : 'help' }}</span>
        </div>
        <div>
          <h2 class="text-base font-semibold">{{ data.title }}</h2>
          <p class="mt-1.5 text-sm text-muted">{{ data.message }}</p>
        </div>
      </div>
      <div class="mt-6 flex justify-end gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn" [class]="data.danger ? 'btn-danger' : 'btn-primary'" (click)="ref.close(true)">
          {{ data.confirmText ?? 'Onayla' }}
        </button>
      </div>
    </div>
  `,
})
export class ConfirmDialog {
  protected readonly data = inject<ConfirmData>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
}

export function confirm(dialog: Dialog, data: ConfirmData): Observable<boolean | undefined> {
  return openModal<boolean>(dialog, ConfirmDialog, data, 'sm');
}
