import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, firstValueFrom, tap } from 'rxjs';
import { API_URL } from './api';
import { Profile } from './models';

const TOKEN_KEY = 'medikal.token';

interface LoginResponse {
  token: string;
  expires_at: string | null;
  user: Profile;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly tokenSignal = signal<string | null>(readToken());
  readonly user = signal<Profile | null>(null);
  readonly isLoggedIn = computed(() => this.tokenSignal() !== null);

  get token(): string | null {
    return this.tokenSignal();
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${API_URL}/auth/login`, { email, password, device_name: 'web-panel' })
      .pipe(
        tap((res) => {
          writeToken(res.token);
          this.tokenSignal.set(res.token);
          this.user.set(res.user);
        }),
      );
  }

  /** Uygulama açılırken token varsa profili (ve güncel yetkileri) yükler. */
  async restore(): Promise<void> {
    if (!this.token) return;
    try {
      const res = await firstValueFrom(this.http.get<{ user: Profile }>(`${API_URL}/auth/me`));
      this.user.set(res.user);
    } catch {
      this.clear();
    }
  }

  logout(): void {
    if (this.token) {
      this.http.post(`${API_URL}/auth/logout`, {}).subscribe({ error: () => {} });
    }
    this.clear();
    this.router.navigateByUrl('/giris');
  }

  /** Token süresi dolduğunda / iptal edildiğinde sunucuya gitmeden çıkış */
  clear(): void {
    writeToken(null);
    this.tokenSignal.set(null);
    this.user.set(null);
  }

  can(permission: string | string[]): boolean {
    const user = this.user();
    if (!user) return false;
    if (user.is_super_admin) return true;
    const list = Array.isArray(permission) ? permission : [permission];
    return list.some((p) => user.permissions.includes(p));
  }
}

function readToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

function writeToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    /* gizli sekme vb. */
  }
}
