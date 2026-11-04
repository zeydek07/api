import { DIALOG_DATA, Dialog, DialogRef } from '@angular/cdk/dialog';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, OnDestroy, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { API_URL, errorMessage, qty } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { DELIVERY_LABELS, Delivery } from '../../core/models';
import { ExpiryBadge } from '../../ui/expiry-badge';
import { ModalFrame } from '../../ui/modal-frame';
import { openModal } from '../../ui/modal';
import { ToastService } from '../../ui/toast';

/** İptal gerekçesi penceresi */
@Component({
  imports: [FormsModule, ModalFrame],
  template: `
    <app-modal title="Belgeyi iptal et" [subtitle]="data.number">
      <div class="space-y-3">
        <div class="flex gap-3 rounded-xl bg-warn-soft p-3.5 text-sm text-warn">
          <span class="icon">info</span>
          <p>
            {{ data.type === 'return' ? 'Ürünler araçtan hastaneye geri taşınır.' : 'Ürünler hastaneden araca geri taşınır.' }}
            İptal kaydı ayrıca saklanır; belge silinmez.
          </p>
        </div>
        <div>
          <label class="label" for="cancel-reason">İptal gerekçesi *</label>
          <textarea id="cancel-reason" class="textarea" rows="3" [(ngModel)]="reason" placeholder="ör. Yanlış hastane seçildi"></textarea>
        </div>
        @if (error()) {
          <p class="form-error">{{ error() }}</p>
        }
      </div>
      <div modal-footer class="flex gap-2">
        <button class="btn btn-secondary" (click)="ref.close(false)">Vazgeç</button>
        <button class="btn btn-danger" [disabled]="reason.trim().length < 3 || saving()" (click)="save()">İptal et</button>
      </div>
    </app-modal>
  `,
})
export class CancelDeliveryDialog {
  protected readonly data = inject<Delivery>(DIALOG_DATA);
  protected readonly ref = inject<DialogRef<boolean>>(DialogRef);
  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected reason = '';
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    this.http.post<{ message: string }>(`${API_URL}/deliveries/${this.data.id}/cancel`, { reason: this.reason.trim() }).subscribe({
      next: (r) => {
        this.toast.success(r.message);
        this.ref.close(true);
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}

/** Yazdırılabilir teslimat belgesi (tarayıcıdan "PDF olarak kaydet" ile PDF olur) */
@Component({
  imports: [DatePipe, RouterLink, ExpiryBadge],
  templateUrl: './delivery-document.html',
})
export class DeliveryDocumentPage implements OnDestroy {
  /** Rota parametresi :id */
  readonly id = input.required<string>();

  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  protected readonly auth = inject(AuthService);
  protected readonly qty = qty;
  protected readonly labels: Record<string, string> = DELIVERY_LABELS;

  protected readonly d = signal<Delivery | null>(null);
  protected readonly notFound = signal(false);
  protected readonly signatureUrl = signal<string | null>(null);
  protected readonly photoUrl = signal<string | null>(null);

  constructor() {
    effect(() => {
      this.id();
      this.load();
    });
  }

  ngOnDestroy(): void {
    this.revoke();
  }

  private load(): void {
    this.http.get<Delivery>(`${API_URL}/deliveries/${this.id()}`).subscribe({
      next: (d) => {
        this.d.set(d);
        this.revoke();
        // Görseller token'lı istekle alınır (img etiketi token gönderemez)
        if (d.has_signature) this.blob(`${API_URL}/deliveries/${d.id}/signature`, (u) => this.signatureUrl.set(u));
        if (d.has_photo) this.blob(`${API_URL}/deliveries/${d.id}/photo`, (u) => this.photoUrl.set(u));
      },
      error: () => this.notFound.set(true),
    });
  }

  private blob(url: string, set: (u: string) => void): void {
    this.http.get(url, { responseType: 'blob' }).subscribe((b) => set(URL.createObjectURL(b)));
  }

  private revoke(): void {
    for (const u of [this.signatureUrl(), this.photoUrl()]) if (u) URL.revokeObjectURL(u);
    this.signatureUrl.set(null);
    this.photoUrl.set(null);
  }

  protected units(): number {
    return (this.d()?.lines ?? []).reduce((s, l) => s + Number(l.quantity), 0);
  }

  protected mapUrl(): string | null {
    const d = this.d();
    return d?.latitude && d.longitude ? `https://www.google.com/maps?q=${d.latitude},${d.longitude}` : null;
  }

  protected print(): void {
    window.print();
  }

  protected cancel(): void {
    const d = this.d();
    if (!d) return;
    openModal<boolean>(this.dialog, CancelDeliveryDialog, d, 'md').subscribe((ok) => ok && this.load());
  }
}
