import { Component, computed, input, signal } from '@angular/core';

export interface BarDatum {
  label: string; // eksende görünen kısa etiket
  tooltip: string; // üzerine gelince başlık
  value: number;
}

/**
 * Tek serili dikey çubuk grafik (SVG).
 * Çubuklar tabana oturur, üst köşeler 4px yuvarlak, çubuklar arası boşluk var,
 * üzerine gelince değer gösterilir. Tek seri olduğu için lejant yok; başlık kartta.
 */
@Component({
  selector: 'app-bar-chart',
  template: `
    <div (mouseleave)="hover.set(-1)">
      <div class="relative h-56">
      <svg [attr.viewBox]="'0 0 ' + W + ' ' + H" class="block h-full w-full" preserveAspectRatio="none" role="img" [attr.aria-label]="ariaLabel()">
        <!-- yatay kılavuz çizgileri -->
        @for (t of ticks(); track t) {
          <line [attr.x1]="padL" [attr.x2]="W" [attr.y1]="y(t)" [attr.y2]="y(t)" class="stroke-line" stroke-width="1" vector-effect="non-scaling-stroke" />
        }
        @for (d of data(); track $index; let i = $index) {
          <!-- geniş görünmez tıklama/hover alanı -->
          <rect [attr.x]="x(i)" y="0" [attr.width]="step()" [attr.height]="plotH" fill="transparent" (mouseenter)="hover.set(i)" />
          @if (d.value > 0) {
            <path [attr.d]="barPath(i, d.value)" class="fill-chart transition-opacity" [attr.opacity]="hover() === -1 || hover() === i ? 1 : 0.45" />
          }
        }
      </svg>

      <!-- y ekseni değerleri -->
      @for (t of ticks(); track t) {
        <span class="pointer-events-none absolute left-0 -translate-y-1/2 text-[11px] tabular-nums text-muted" [style.top.%]="(y(t) / H) * 100">{{ t }}</span>
      }

      @if (hover() >= 0 && data()[hover()]; as d) {
        <div
          class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-lg border border-line bg-surface px-3 py-2 text-xs whitespace-nowrap shadow-lg"
          [style.left.%]="((x(hover()) + step() / 2) / W) * 100"
          [style.top.%]="(y(d.value) / H) * 100"
          [style.margin-top.px]="-8"
        >
          <div class="text-muted">{{ d.tooltip }}</div>
          <div class="mt-0.5 font-semibold text-ink tabular-nums">{{ d.value }} {{ unit() }}</div>
        </div>
      }
      </div>

      <!-- x ekseni etiketleri (seyrekleştirilmiş) -->
      <div class="mt-2 flex text-[11px] text-muted" [style.padding-left.%]="(padL / W) * 100">
        @for (d of data(); track $index; let i = $index) {
          <span class="flex-1 text-center tabular-nums">{{ i % labelEvery() === 0 || i === data().length - 1 ? d.label : '' }}</span>
        }
      </div>

    </div>
  `,
})
export class BarChart {
  readonly data = input.required<BarDatum[]>();
  readonly unit = input('');
  readonly ariaLabel = input('Çubuk grafik');

  protected readonly W = 600;
  protected readonly H = 220;
  protected readonly padL = 28;
  protected readonly plotH = this.H;
  protected readonly hover = signal(-1);

  protected readonly max = computed(() => {
    const m = Math.max(0, ...this.data().map((d) => d.value));
    return niceMax(m);
  });

  protected readonly ticks = computed(() => {
    const m = this.max();
    const step = m / 4;
    return [0, step, step * 2, step * 3, m].map((v) => Math.round(v));
  });

  protected readonly step = computed(() => (this.W - this.padL) / Math.max(1, this.data().length));
  protected readonly labelEvery = computed(() => Math.ceil(this.data().length / 7));

  protected x(i: number): number {
    return this.padL + i * this.step();
  }

  protected y(v: number): number {
    const top = 8;
    return top + (this.H - top) * (1 - v / this.max());
  }

  /** Üst köşeleri yuvarlatılmış, tabana oturan çubuk; yanlarda 2px+ boşluk */
  protected barPath(i: number, v: number): string {
    const gap = Math.max(2, this.step() * 0.28);
    const x0 = this.x(i) + gap / 2;
    const w = this.step() - gap;
    const yTop = this.y(v);
    const base = this.H;
    const r = Math.min(4, w / 2, base - yTop);
    return `M${x0},${base} V${yTop + r} Q${x0},${yTop} ${x0 + r},${yTop} H${x0 + w - r} Q${x0 + w},${yTop} ${x0 + w},${yTop + r} V${base} Z`;
  }
}

function niceMax(v: number): number {
  if (v <= 4) return 4;
  const pow = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / pow;
  const nice = n <= 1 ? 1 : n <= 2 ? 2 : n <= 4 ? 4 : n <= 5 ? 5 : 10;
  return nice * pow;
}
