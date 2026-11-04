import { Dialog } from '@angular/cdk/dialog';
import { CdkMenu, CdkMenuItem, CdkMenuTrigger } from '@angular/cdk/menu';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { debounceTime, merge } from 'rxjs';
import { API_URL, errorMessage, toParams } from '../../core/api';
import { CanDirective } from '../../core/can.directive';
import { HOSPITAL_LABELS, HOSPITAL_TYPES, Hospital, Page } from '../../core/models';
import { ProvinceService } from '../../core/province.service';
import { SearchSelect } from '../../ui/search-select';
import { confirm } from '../../ui/confirm';
import { openModal } from '../../ui/modal';
import { Pagination } from '../../ui/pagination';
import { ToastService } from '../../ui/toast';
import { HospitalDialog } from './hospital-dialog';

@Component({
  imports: [
    DatePipe,
    ReactiveFormsModule,
    RouterLink,
    CanDirective,
    Pagination,
    SearchSelect,
    CdkMenuTrigger,
    CdkMenu,
    CdkMenuItem,
  ],
  templateUrl: './hospitals.html',
})
export class HospitalsPage {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  protected readonly page = signal<Page<Hospital> | null>(null);
  protected readonly provinces = inject(ProvinceService).options();
  protected readonly loading = signal(false);
  protected readonly typeLabels: Record<string, string> = HOSPITAL_LABELS;
  protected readonly types = HOSPITAL_TYPES;

  protected readonly q = new FormControl('', { nonNullable: true });
  protected readonly city = new FormControl('', { nonNullable: true });
  protected readonly type = new FormControl('', { nonNullable: true });
  private pageNo = 1;
  private perPage = 25;

  constructor() {
    merge(this.q.valueChanges.pipe(debounceTime(300)), this.city.valueChanges, this.type.valueChanges)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        this.pageNo = 1;
        this.load();
      });
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    const params = toParams({
      q: this.q.value,
      city: this.city.value,
      type: this.type.value,
      page: this.pageNo,
      per_page: this.perPage,
    });
    this.http.get<Page<Hospital>>(`${API_URL}/hospitals`, { params }).subscribe({
      next: (p) => {
        this.page.set(p);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected goTo(page: number): void {
    this.pageNo = page;
    this.load();
  }

  protected setSize(size: number): void {
    this.perPage = size;
    this.pageNo = 1;
    this.load();
  }

  protected openDetail(h: Hospital): void {
    this.router.navigate(['/hastaneler', h.id]);
  }

  protected open(hospital?: Hospital): void {
    openModal<boolean>(this.dialog, HospitalDialog, { hospital }, 'lg').subscribe((saved) => saved && this.load());
  }

  protected remove(h: Hospital): void {
    confirm(this.dialog, {
      title: 'Hastaneyi sil',
      message: `"${h.name}" silinsin mi? Geçmiş teslimat kayıtları korunur, yeni teslimatta seçilemez.`,
      confirmText: 'Sil',
      danger: true,
    }).subscribe((ok) => {
      if (!ok) return;
      this.http.delete(`${API_URL}/hospitals/${h.id}`).subscribe({
        next: () => {
          this.toast.success('Hastane silindi.');
          this.load();
        },
        error: (e) => this.toast.error(errorMessage(e)),
      });
    });
  }
}
