import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, catchError, of, shareReplay } from 'rxjs';
import { SelectOption } from '../ui/search-select';
import { API_URL } from './api';

export interface Province {
  code: number;
  name: string;
}

/** 81 il; API'den bir kez yüklenir, uygulama boyunca paylaşılır. */
@Injectable({ providedIn: 'root' })
export class ProvinceService {
  private readonly http = inject(HttpClient);
  private requested = false;
  private readonly list = signal<Province[]>([]);

  /** Türkçe alfabe sırasıyla iller (ilk çağrıda yüklenir) */
  provinces() {
    if (!this.requested) {
      this.requested = true;
      this.http.get<Province[]>(`${API_URL}/provinces`).subscribe({
        next: (p) => this.list.set(p),
        error: () => (this.requested = false),
      });
    }
    return this.list.asReadonly();
  }

  private readonly districtCache = new Map<number, Observable<string[]>>();

  /** İl adından ilçe listesi (il başına bir kez yüklenir) */
  districtsOf(province: string | null): Observable<string[]> {
    const code = this.list().find((p) => p.name === province)?.code;
    if (!code) return of([]);
    if (!this.districtCache.has(code)) {
      this.districtCache.set(
        code,
        this.http.get<string[]>(`${API_URL}/provinces/${code}/districts`).pipe(
          catchError(() => {
            this.districtCache.delete(code);
            return of([]);
          }),
          shareReplay(1),
        ),
      );
    }
    return this.districtCache.get(code)!;
  }

  /** Aramalı seçim kutusu için: değer ve etiket il adı, yanında plaka kodu */
  options() {
    const list = this.provinces();
    return computed<SelectOption[]>(() =>
      list().map((p) => ({ value: p.name, label: p.name, hint: String(p.code).padStart(2, '0') })),
    );
  }
}
