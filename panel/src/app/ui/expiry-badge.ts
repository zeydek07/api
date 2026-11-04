import { Component, computed, input } from '@angular/core';

/** API'deki config/inventory.php ile aynı eşikler */
export const EXPIRY_WARNING_DAYS = 90;
export const EXPIRY_CRITICAL_DAYS = 30;

export type ExpiryLevel = 'expired' | 'critical' | 'warning' | 'ok' | 'none';

export function daysUntil(date: string | null | undefined): number | null {
  if (!date) return null;
  const d = new Date(date.slice(0, 10) + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return Math.round((d.getTime() - today.getTime()) / 86400000);
}

export function expiryLevel(date: string | null | undefined): ExpiryLevel {
  const days = daysUntil(date);
  if (days === null) return 'none';
  if (days < 0) return 'expired';
  if (days <= EXPIRY_CRITICAL_DAYS) return 'critical';
  if (days <= EXPIRY_WARNING_DAYS) return 'warning';
  return 'ok';
}

/**
 * SKT rozeti: tarih + kalan süre, renk durumla eşleşir.
 * Durum yalnızca renkle değil, metin ve simgeyle de anlatılır.
 */
@Component({
  selector: 'app-expiry-badge',
  template: `
    @if (date(); as d) {
      <span class="badge" [class]="cls()" [title]="title()">
        @if (level() === 'expired' || level() === 'critical') {
          <span class="icon text-[14px]" aria-hidden="true">{{ level() === 'expired' ? 'block' : 'warning' }}</span>
        }
        {{ formatted() }}
        @if (!compact()) {
          <span class="opacity-80">· {{ remaining() }}</span>
        }
      </span>
    } @else {
      <span class="text-muted">—</span>
    }
  `,
})
export class ExpiryBadge {
  readonly date = input<string | null | undefined>(null);
  /** Yalnızca tarihi göster */
  readonly compact = input(false);

  protected readonly level = computed(() => expiryLevel(this.date()));

  protected readonly cls = computed(
    () =>
      ({
        expired: 'badge-danger',
        critical: 'badge-danger',
        warning: 'badge-warn',
        ok: 'badge',
        none: 'badge',
      })[this.level()],
  );

  protected readonly formatted = computed(() => {
    const d = this.date();
    if (!d) return '';
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day}.${m}.${y}`;
  });

  protected readonly remaining = computed(() => {
    const days = daysUntil(this.date());
    if (days === null) return '';
    if (days < 0) return `${-days} gün geçti`;
    if (days === 0) return 'bugün doluyor';
    if (days <= 60) return `${days} gün`;
    if (days < 730) return `${Math.round(days / 30)} ay`;
    return `${Math.round(days / 365)} yıl`;
  });

  protected readonly title = computed(() => {
    const l = this.level();
    if (l === 'expired') return "SKT geçmiş: kullanılamaz";
    if (l === 'critical') return `SKT'ye ${EXPIRY_CRITICAL_DAYS} günden az kaldı`;
    if (l === 'warning') return `SKT'ye ${EXPIRY_WARNING_DAYS} günden az kaldı`;
    return 'Son kullanma tarihi';
  });
}
