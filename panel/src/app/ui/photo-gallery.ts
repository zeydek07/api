import { HttpClient } from '@angular/common/http';
import { Component, HostListener, OnDestroy, effect, inject, input, output, signal, untracked } from '@angular/core';
import { API_URL, errorMessage } from '../core/api';
import { compressImage } from '../core/image';
import { ProofPhoto } from '../core/models';
import { ToastService } from './toast';

const MAX_PHOTOS = 10;

/**
 * Belge fotoğraf galerisi: küçük resimler + etiket, tıklayınca büyük görünüm.
 * Dosyalar token'lı istekle alınır; nesne adresleri yenilenirken/yıkılırken serbest bırakılır.
 */
@Component({
  selector: 'app-photo-gallery',
  template: `
    @if (photos().length || canAdd()) {
      <div class="flex items-center gap-3">
        <h2 class="doc-label flex-1">Fotoğraflar @if (photos().length) { ({{ photos().length }}) }</h2>
        @if (canAdd() && photos().length < max) {
          <input #file type="file" accept="image/*" multiple class="sr-only" aria-label="Fotoğraf dosyası seç" (change)="onFiles(file)" />
          <button type="button" class="btn btn-secondary btn-sm mb-1.5" [disabled]="uploading()" (click)="file.click()">
            <span class="icon text-[18px]">add_a_photo</span> {{ uploading() ? 'Yükleniyor…' : 'Fotoğraf ekle' }}
          </button>
        }
      </div>
      @if (error()) {
        <p class="form-error mb-2" role="alert">{{ error() }}</p>
      }
      @if (photos().length) {
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4" data-testid="photo-gallery">
          @for (p of photos(); track p.id) {
            <li>
              <button type="button" class="block w-full text-left" [attr.aria-label]="'Fotoğrafı büyüt: ' + p.label" (click)="open(p)">
                @if (urls()[p.id]; as u) {
                  <img [src]="u" [alt]="p.label" class="h-28 w-full rounded-xl border border-line bg-surface-2 object-cover" />
                } @else {
                  <div class="grid h-28 place-items-center rounded-xl border border-dashed border-line text-xs text-muted">Yükleniyor…</div>
                }
              </button>
              <div class="mt-1 text-xs font-medium text-ink">{{ p.label }}</div>
            </li>
          }
        </ul>
      } @else {
        <p class="rounded-xl bg-surface-2 px-4 py-3 text-sm text-muted">Fotoğraf eklenmemiş.</p>
      }
    }

    @if (view(); as v) {
      <div class="fixed inset-0 z-50 grid place-items-center bg-black/80 p-4 print:hidden" role="dialog" aria-modal="true" [attr.aria-label]="v.photo.label" (click)="view.set(null)">
        <figure class="max-h-full max-w-full" (click)="$event.stopPropagation()">
          <img [src]="v.url" [alt]="v.photo.label" class="max-h-[85vh] max-w-full rounded-lg bg-white object-contain" />
          <figcaption class="mt-2 text-center text-sm text-white">{{ v.photo.label }}</figcaption>
        </figure>
        <button type="button" class="btn btn-secondary absolute right-4 top-4" (click)="view.set(null)"><span class="icon">close</span> Kapat</button>
      </div>
    }
  `,
})
export class PhotoGallery implements OnDestroy {
  readonly photos = input<ProofPhoto[]>([]);
  /** ör. /cases/5 veya /deliveries/7 (API_URL'siz) */
  readonly base = input.required<string>();
  readonly canAdd = input(false);
  /** Fotoğraf eklenince belge yeniden yüklensin */
  readonly changed = output<void>();

  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastService);
  protected readonly max = MAX_PHOTOS;
  protected readonly urls = signal<Record<number, string>>({});
  protected readonly view = signal<{ photo: ProofPhoto; url: string } | null>(null);
  protected readonly uploading = signal(false);
  protected readonly error = signal('');
  private seq = 0;

  constructor() {
    effect(() => {
      const list = this.photos();
      const base = this.base();
      untracked(() => this.fetch(list, base));
    });
  }

  ngOnDestroy(): void {
    this.seq++;
    this.revokeAll();
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    this.view.set(null);
  }

  private fetch(list: ProofPhoto[], base: string): void {
    const n = ++this.seq;
    this.revokeAll();
    this.view.set(null);
    for (const p of list) {
      this.http.get(`${API_URL}${base}/photos/${p.id}`, { responseType: 'blob' }).subscribe({
        next: (b) => {
          if (n !== this.seq) return;
          this.urls.update((m) => ({ ...m, [p.id]: URL.createObjectURL(b) }));
        },
        error: () => {},
      });
    }
  }

  private revokeAll(): void {
    for (const u of Object.values(this.urls())) URL.revokeObjectURL(u);
    this.urls.set({});
  }

  protected open(p: ProofPhoto): void {
    const url = this.urls()[p.id];
    if (url) this.view.set({ photo: p, url });
  }

  protected async onFiles(input: HTMLInputElement): Promise<void> {
    const files = Array.from(input.files ?? []).slice(0, MAX_PHOTOS - this.photos().length);
    input.value = '';
    if (!files.length || this.uploading()) return;
    this.uploading.set(true);
    this.error.set('');
    try {
      const photos: { data: string; kind: string }[] = [];
      for (const f of files) photos.push({ data: await compressImage(f, 1600, 0.75), kind: 'other' });
      const total = photos.reduce((s, p) => s + p.data.length, 0);
      if (total > 4_500_000) throw new Error('Fotoğraflar çok büyük; daha az sayıda ya da daha küçük seçin.');
      await new Promise<void>((resolve, reject) =>
        this.http.post<{ message?: string }>(`${API_URL}${this.base()}/photos`, { photos }).subscribe({
          next: (r) => {
            if (r?.message) this.toast.success(r.message);
            resolve();
          },
          error: (e) => reject(new Error(errorMessage(e))),
        }),
      );
      this.changed.emit();
    } catch (e) {
      this.error.set(e instanceof Error ? e.message : 'Fotoğraf eklenemedi.');
    } finally {
      this.uploading.set(false);
    }
  }
}
