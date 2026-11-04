import { Injectable, effect, signal } from '@angular/core';

type Theme = 'light' | 'dark';
const KEY = 'medikal.theme';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly theme = signal<Theme>(initial());

  constructor() {
    effect(() => {
      const t = this.theme();
      document.documentElement.classList.toggle('dark', t === 'dark');
      try {
        localStorage.setItem(KEY, t);
      } catch {
        /* yoksay */
      }
    });
  }

  toggle(): void {
    this.theme.update((t) => (t === 'dark' ? 'light' : 'dark'));
  }
}

function initial(): Theme {
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === 'dark' || saved === 'light') return saved;
  } catch {
    /* yoksay */
  }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
