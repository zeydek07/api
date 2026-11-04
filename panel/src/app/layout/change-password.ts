import { DialogRef } from '@angular/cdk/dialog';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { API_URL, errorMessage } from '../core/api';
import { ModalFrame } from '../ui/modal-frame';
import { ToastService } from '../ui/toast';

@Component({
  imports: [ReactiveFormsModule, ModalFrame],
  template: `
    <form [formGroup]="form" (ngSubmit)="save()">
      <app-modal title="Şifre değiştir" subtitle="Diğer cihazlardaki oturumlarınız kapatılır.">
        <div class="space-y-4">
          <div>
            <label class="label" for="cp1">Mevcut şifre</label>
            <input id="cp1" class="input" type="password" formControlName="current_password" autocomplete="current-password" />
          </div>
          <div>
            <label class="label" for="cp2">Yeni şifre</label>
            <input id="cp2" class="input" type="password" formControlName="password" autocomplete="new-password" />
            <p class="hint">En az 8 karakter</p>
          </div>
          <div>
            <label class="label" for="cp3">Yeni şifre (tekrar)</label>
            <input id="cp3" class="input" type="password" formControlName="password_confirmation" autocomplete="new-password" />
          </div>
          @if (error()) {
            <p class="form-error">{{ error() }}</p>
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
export class ChangePasswordDialog {
  private readonly http = inject(HttpClient);
  protected readonly ref = inject(DialogRef);
  private readonly toast = inject(ToastService);

  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly form = inject(FormBuilder).nonNullable.group({
    current_password: ['', Validators.required],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
  });

  protected save(): void {
    this.saving.set(true);
    this.error.set('');
    this.http.post(`${API_URL}/auth/change-password`, this.form.getRawValue()).subscribe({
      next: () => {
        this.toast.success('Şifreniz güncellendi.');
        this.ref.close();
      },
      error: (e) => {
        this.error.set(errorMessage(e));
        this.saving.set(false);
      },
    });
  }
}
