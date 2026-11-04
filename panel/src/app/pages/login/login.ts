import { Component, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { errorMessage } from '../../core/api';
import { AuthService } from '../../core/auth.service';

@Component({
  imports: [ReactiveFormsModule],
  templateUrl: './login.html',
})
export class LoginPage {
  /** Giriş sonrası dönülecek sayfa (?r=/urunler) */
  readonly r = input<string>();

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly year = new Date().getFullYear();
  protected readonly loading = signal(false);
  protected readonly error = signal('');
  protected readonly showPassword = signal(false);
  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.error.set('E-posta ve şifrenizi girin.');
      return;
    }
    this.loading.set(true);
    this.error.set('');
    const { email, password } = this.form.getRawValue();

    this.auth.login(email, password).subscribe({
      next: () => this.router.navigateByUrl(this.r() || '/'),
      error: (e) => {
        this.error.set(errorMessage(e));
        this.loading.set(false);
      },
    });
  }
}
