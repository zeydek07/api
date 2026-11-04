import { Injectable, inject } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';

/** Sekme başlığı: "Ürünler · Medikal Stok" */
@Injectable()
export class AppTitleStrategy extends TitleStrategy {
  private readonly title = inject(Title);

  override updateTitle(snapshot: RouterStateSnapshot): void {
    const t = this.buildTitle(snapshot);
    this.title.setTitle(t ? `${t} · Medikal Stok` : 'Medikal Stok');
  }
}
