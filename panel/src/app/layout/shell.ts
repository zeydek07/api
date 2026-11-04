import { Dialog } from '@angular/cdk/dialog';
import { CdkMenu, CdkMenuItem, CdkMenuTrigger } from '@angular/cdk/menu';
import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { openModal } from '../ui/modal';
import { ThemeService } from '../ui/theme.service';
import { ChangePasswordDialog } from './change-password';
import { MENU_GROUPS } from './menu';

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, CdkMenuTrigger, CdkMenu, CdkMenuItem],
  templateUrl: './shell.html',
})
export class Shell {
  protected readonly auth = inject(AuthService);
  protected readonly theme = inject(ThemeService);
  private readonly dialog = inject(Dialog);
  private readonly router = inject(Router);

  /** Mobilde kenar menü açık mı */
  protected readonly navOpen = signal(false);

  protected readonly groups = computed(() => {
    this.auth.user(); // yetkiler değişince yeniden hesapla
    return MENU_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => this.auth.can(i.permission)) })).filter(
      (g) => g.items.length,
    );
  });

  protected readonly initials = computed(() =>
    (this.auth.user()?.name ?? '?')
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0]!.toLocaleUpperCase('tr'))
      .join(''),
  );

  /** Üst çubuktaki sayfa başlığı: aktif rotanın "title" değeri */
  protected readonly heading = toSignal(
    this.router.events.pipe(
      filter((e) => e instanceof NavigationEnd),
      map(() => deepestTitle(this.router)),
    ),
    { initialValue: deepestTitle(this.router) },
  );

  constructor() {
    this.router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => this.navOpen.set(false));
  }

  protected changePassword(): void {
    openModal(this.dialog, ChangePasswordDialog, undefined, 'sm');
  }
}

function deepestTitle(router: Router): string {
  let route = router.routerState.snapshot.root;
  while (route.firstChild) route = route.firstChild;
  return route.title ?? '';
}
