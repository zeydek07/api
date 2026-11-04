import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  if (auth.user()) return true;
  return inject(Router).createUrlTree(['/giris'], { queryParams: { r: state.url } });
};

export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.user() ? inject(Router).createUrlTree(['/']) : true;
};

/** Route data: { permission: 'products.view' } veya ['a', 'b'] (biri yeterli) */
export const permissionGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const required = route.data['permission'] as string | string[] | undefined;
  if (!required || auth.can(required)) return true;
  return inject(Router).createUrlTree(['/yetkisiz']);
};
