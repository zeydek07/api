import { Routes } from '@angular/router';
import { authGuard, guestGuard, permissionGuard } from './core/guards';
import { Shell } from './layout/shell';

export const routes: Routes = [
  {
    path: 'giris',
    canActivate: [guestGuard],
    title: 'Giriş',
    loadComponent: () => import('./pages/login/login').then((m) => m.LoginPage),
  },
  {
    path: '',
    component: Shell,
    canActivate: [authGuard],
    canActivateChild: [permissionGuard],
    children: [
      { path: '', pathMatch: 'full', loadComponent: () => import('./pages/home/home').then((m) => m.HomePage) },
      {
        path: 'panel',
        title: 'Kontrol Paneli',
        data: { permission: 'dashboard.view' },
        loadComponent: () => import('./pages/dashboard/dashboard').then((m) => m.DashboardPage),
      },
      {
        path: 'teslimat',
        title: 'Hastaneye Teslim',
        data: { permission: 'usage.create' },
        loadComponent: () => import('./pages/delivery/delivery').then((m) => m.DeliveryPage),
      },
      {
        path: 'hastaneler',
        title: 'Hastaneler',
        data: { permission: 'hospitals.view' },
        loadComponent: () => import('./pages/hospitals/hospitals').then((m) => m.HospitalsPage),
      },
      {
        path: 'hastaneler/:id',
        title: 'Hastane',
        data: { permission: 'hospitals.view' },
        loadComponent: () => import('./pages/hospitals/hospital-detail').then((m) => m.HospitalDetailPage),
      },
      {
        path: 'personeller',
        title: 'Personeller',
        data: { permission: 'users.view' },
        loadComponent: () => import('./pages/users/users').then((m) => m.UsersPage),
      },
      {
        path: 'roller',
        title: 'Roller ve Yetkiler',
        data: { permission: 'roles.view' },
        loadComponent: () => import('./pages/roles/roles').then((m) => m.RolesPage),
      },
      {
        path: 'urunler',
        title: 'Ürünler',
        data: { permission: 'products.view' },
        loadComponent: () => import('./pages/products/products').then((m) => m.ProductsPage),
      },
      {
        path: 'depolar',
        title: 'Depolar',
        data: { permission: 'warehouses.view' },
        loadComponent: () => import('./pages/warehouses/warehouses').then((m) => m.WarehousesPage),
      },
      {
        path: 'stok',
        title: 'Stok',
        data: { permission: 'stock.view' },
        loadComponent: () => import('./pages/stock/stock').then((m) => m.StockPage),
      },
      {
        path: 'hareketler',
        title: 'Stok Hareketleri',
        data: { permission: ['movements.view_team', 'movements.view_all'] },
        loadComponent: () => import('./pages/movements/movements').then((m) => m.MovementsPage),
      },
      {
        path: 'raporlar',
        title: 'Raporlar',
        data: { permission: 'reports.view' },
        loadComponent: () => import('./pages/reports/reports').then((m) => m.ReportsPage),
      },
      {
        path: 'yetkisiz',
        title: 'Yetkisiz',
        loadComponent: () => import('./pages/home/home').then((m) => m.HomePage),
        data: { denied: true },
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
