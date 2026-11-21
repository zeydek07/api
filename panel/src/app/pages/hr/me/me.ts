import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { API_URL, errorMessage } from '../../../core/api';
import { HrMe } from '../../../core/hr.models';

@Component({
  templateUrl: './me.html',
})
export class MePage {
  private readonly http = inject(HttpClient);

  protected readonly me = signal<HrMe | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.http.get<{ data: HrMe }>(`${API_URL}/hr/me`).subscribe({
      next: (r) => {
        this.me.set(r.data);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
  }
}
