import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { Toaster } from './ui/toast';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, Toaster],
  template: `<router-outlet /><app-toaster />`,
})
export class App {}
