import { Dialog, DialogConfig } from '@angular/cdk/dialog';
import { ComponentType } from '@angular/cdk/portal';
import { Observable } from 'rxjs';

export const MODAL_DEFAULTS: DialogConfig = {
  hasBackdrop: true,
  backdropClass: 'modal-backdrop',
  panelClass: 'modal-panel',
  autoFocus: 'first-tabbable',
  restoreFocus: true,
};

export type ModalSize = 'sm' | 'md' | 'lg' | 'xl';
const WIDTHS: Record<ModalSize, string> = { sm: '420px', md: '560px', lg: '720px', xl: '880px' };

/** Pencere açar; kapanınca dönen değeri verir. */
export function openModal<R = unknown, D = unknown, C = unknown>(
  dialog: Dialog,
  component: ComponentType<C>,
  data?: D,
  size: ModalSize = 'md',
): Observable<R | undefined> {
  return dialog.open<R, D, C>(component, { data, width: WIDTHS[size] }).closed;
}
