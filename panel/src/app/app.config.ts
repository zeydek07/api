import { DEFAULT_DIALOG_CONFIG } from '@angular/cdk/dialog';
import { registerLocaleData } from '@angular/common';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import localeTr from '@angular/common/locales/tr';
import {
  ApplicationConfig,
  LOCALE_ID,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { TitleStrategy, provideRouter, withComponentInputBinding } from '@angular/router';
import { routes } from './app.routes';
import { authInterceptor } from './core/auth.interceptor';
import { AuthService } from './core/auth.service';
import { AppTitleStrategy } from './core/title-strategy';
import { MODAL_DEFAULTS } from './ui/modal';
import { ThemeService } from './ui/theme.service';

registerLocaleData(localeTr);

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([authInterceptor])),
    { provide: LOCALE_ID, useValue: 'tr' },
    { provide: TitleStrategy, useClass: AppTitleStrategy },
    { provide: DEFAULT_DIALOG_CONFIG, useValue: MODAL_DEFAULTS },
    provideAppInitializer(() => {
      inject(ThemeService); // kayıtlı temayı uygula
      // Sayfa yenilendiğinde oturumu ve güncel yetkileri yükle
      return inject(AuthService).restore();
    }),
  ],
};
