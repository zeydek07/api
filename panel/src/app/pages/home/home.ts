import { Component, OnInit, computed, inject, input } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MENU } from '../../layout/menu';

/** Giriş sonrası: yetkisi olan ilk sayfaya yönlendirir. Hiç yetki yoksa bilgi verir. */
@Component({
  imports: [RouterLink],
  template: `
    <div class="card mx-auto mt-10 max-w-lg px-6 py-12 text-center">
      <div
        class="mx-auto grid size-14 place-items-center rounded-full"
        [class]="denied() ? 'bg-danger-soft text-danger' : 'bg-primary-soft text-primary-ink'"
      >
        <span class="icon text-[28px]">{{ denied() ? 'block' : 'waving_hand' }}</span>
      </div>
      @if (denied()) {
        <h2 class="mt-5 text-lg font-semibold">Bu sayfayı görme yetkiniz yok</h2>
        <p class="mt-2 text-muted">Erişim için yöneticinizle iletişime geçin.</p>
      } @else {
        <h2 class="mt-5 text-lg font-semibold">Hoş geldiniz, {{ auth.user()?.name }}</h2>
        <p class="mt-2 text-muted">Hesabınıza panelde görüntülenebilecek bir sayfa yetkisi tanımlanmamış.</p>
      }
      @if (first(); as f) {
        <a class="btn btn-primary mt-6" [routerLink]="f.path">{{ f.label }} sayfasına git</a>
      }
    </div>
  `,
})
export class HomePage implements OnInit {
  readonly denied = input(false);
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly first = computed(() => MENU.find((m) => this.auth.can(m.permission)));

  ngOnInit(): void {
    const first = this.first();
    if (!this.denied() && first) {
      this.router.navigateByUrl(first.path, { replaceUrl: true });
    }
  }
}
