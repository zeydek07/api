import { Directive, TemplateRef, ViewContainerRef, effect, inject, input } from '@angular/core';
import { AuthService } from './auth.service';

/**
 * Yetkisi olmayan kullanıcıdan öğeyi gizler:
 * <button *appCan="'products.create'">Yeni ürün</button>
 */
@Directive({ selector: '[appCan]' })
export class CanDirective {
  readonly appCan = input.required<string | string[]>();

  private readonly auth = inject(AuthService);
  private readonly tpl = inject(TemplateRef);
  private readonly vcr = inject(ViewContainerRef);
  private shown = false;

  constructor() {
    effect(() => {
      const allowed = this.auth.can(this.appCan());
      if (allowed && !this.shown) {
        this.vcr.createEmbeddedView(this.tpl);
        this.shown = true;
      } else if (!allowed && this.shown) {
        this.vcr.clear();
        this.shown = false;
      }
    });
  }
}
