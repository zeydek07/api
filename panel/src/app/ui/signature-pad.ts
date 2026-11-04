import { AfterViewInit, Component, ElementRef, OnDestroy, output, signal, viewChild } from '@angular/core';

/**
 * Parmak / fare / kalemle imza alanı.
 * Her zaman beyaz zemin üzerine koyu mürekkep çizer (koyu temada da belge gibi görünsün).
 * İmza bitince PNG data URL'i "signed" ile, temizlenince null döner.
 */
@Component({
  selector: 'app-signature-pad',
  template: `
    <div class="relative overflow-hidden rounded-xl border-2 border-dashed bg-white" [class]="empty() ? 'border-line' : 'border-primary/60'">
      <canvas
        #canvas
        class="block h-44 w-full touch-none"
        role="img"
        aria-label="İmza alanı: parmağınızla ya da fareyle imzalayın"
        (pointerdown)="start($event)"
        (pointermove)="move($event)"
        (pointerup)="end()"
        (pointerleave)="end()"
        (pointercancel)="end()"
      ></canvas>
      @if (empty()) {
        <div class="pointer-events-none absolute inset-0 grid place-items-center text-sm text-slate-400">
          <span class="flex items-center gap-2"><span class="icon">draw</span> Buraya imzalayın</span>
        </div>
      }
      <div class="pointer-events-none absolute inset-x-6 bottom-8 border-b border-slate-300"></div>
      @if (!empty()) {
        <button type="button" class="absolute right-2 top-2 rounded-lg bg-white/90 px-2.5 py-1 text-xs font-medium text-slate-600 shadow ring-1 ring-slate-200 hover:bg-slate-50" (click)="clear()">
          Temizle
        </button>
      }
    </div>
  `,
})
export class SignaturePad implements AfterViewInit, OnDestroy {
  readonly signed = output<string | null>();

  protected readonly empty = signal(true);
  private readonly canvas = viewChild.required<ElementRef<HTMLCanvasElement>>('canvas');
  private ctx!: CanvasRenderingContext2D;
  private drawing = false;
  private last: { x: number; y: number } | null = null;
  private resizeObserver?: ResizeObserver;

  ngAfterViewInit(): void {
    this.setup();
    this.resizeObserver = new ResizeObserver(() => {
      // Boyut değişirse (ekran döndürme) temizlemek zorundayız; imza bozulmasın
      if (this.empty()) this.setup();
    });
    this.resizeObserver.observe(this.canvas().nativeElement);
  }

  ngOnDestroy(): void {
    this.resizeObserver?.disconnect();
  }

  private setup(): void {
    const el = this.canvas().nativeElement;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const rect = el.getBoundingClientRect();
    el.width = Math.max(1, Math.round(rect.width * ratio));
    el.height = Math.max(1, Math.round(rect.height * ratio));
    this.ctx = el.getContext('2d')!;
    this.ctx.scale(ratio, ratio);
    this.ctx.fillStyle = '#ffffff';
    this.ctx.fillRect(0, 0, rect.width, rect.height);
    this.ctx.lineWidth = 2.2;
    this.ctx.lineCap = 'round';
    this.ctx.lineJoin = 'round';
    this.ctx.strokeStyle = '#0f172a';
  }

  private point(e: PointerEvent) {
    const r = this.canvas().nativeElement.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  protected start(e: PointerEvent): void {
    e.preventDefault();
    this.canvas().nativeElement.setPointerCapture?.(e.pointerId);
    this.drawing = true;
    this.last = this.point(e);
    // Tek dokunuş da iz bıraksın
    this.ctx.beginPath();
    this.ctx.arc(this.last.x, this.last.y, 1.1, 0, Math.PI * 2);
    this.ctx.fillStyle = '#0f172a';
    this.ctx.fill();
  }

  protected move(e: PointerEvent): void {
    if (!this.drawing || !this.last) return;
    const p = this.point(e);
    this.ctx.beginPath();
    this.ctx.moveTo(this.last.x, this.last.y);
    this.ctx.lineTo(p.x, p.y);
    this.ctx.stroke();
    this.last = p;
    if (this.empty()) this.empty.set(false);
  }

  protected end(): void {
    if (!this.drawing) return;
    this.drawing = false;
    this.last = null;
    if (!this.empty()) this.signed.emit(this.canvas().nativeElement.toDataURL('image/png'));
  }

  clear(): void {
    this.setup();
    this.empty.set(true);
    this.signed.emit(null);
  }
}
