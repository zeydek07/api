import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { API_URL, errorMessage } from './api';
import { ToastService } from '../ui/toast';
import { AuthService } from './auth.service';

/** Her API isteğine Bearer token ekler; 401/403/429/5xx hatalarını ortak ele alır. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const toast = inject(ToastService);

  const isApi = req.url.startsWith(API_URL);
  const token = auth.token;
  const request =
    isApi && token
      ? req.clone({ setHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      : req.clone({ setHeaders: { Accept: 'application/json' } });

  return next(request).pipe(
    catchError((err: HttpErrorResponse) => {
      if (!isApi) return throwError(() => err);

      if (err.status === 401 && !req.url.endsWith('/auth/login')) {
        auth.clear();
        toast.info('Oturumunuz sona erdi. Lütfen tekrar giriş yapın.');
        router.navigate(['/giris'], { queryParams: { r: router.url } });
      } else if (err.status === 403) {
        toast.error(errorMessage(err));
        if (err.error?.message?.includes('pasif')) {
          auth.clear();
          router.navigateByUrl('/giris');
        }
      } else if (err.status === 429 || err.status === 0 || err.status >= 500) {
        toast.error(errorMessage(err));
      }
      // 422 doğrulama hataları ilgili formda gösterilir.
      return throwError(() => err);
    }),
  );
};
