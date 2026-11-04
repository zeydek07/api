export interface MenuItem {
  label: string;
  icon: string;
  path: string;
  permission: string | string[];
}

export interface MenuGroup {
  title: string;
  items: MenuItem[];
}

/** Menü; kullanıcının yetkisi olmayan öğeler (ve boş kalan gruplar) görünmez. */
export const MENU_GROUPS: MenuGroup[] = [
  {
    title: 'Genel',
    items: [{ label: 'Kontrol Paneli', icon: 'space_dashboard', path: '/panel', permission: 'dashboard.view' }],
  },
  {
    title: 'Saha',
    items: [
      { label: 'Hastaneye Teslim', icon: 'local_hospital', path: '/teslimat', permission: 'usage.create' },
      { label: 'Hastaneler', icon: 'domain', path: '/hastaneler', permission: 'hospitals.view' },
    ],
  },
  {
    title: 'Stok Yönetimi',
    items: [
      { label: 'Ürünler', icon: 'medication', path: '/urunler', permission: 'products.view' },
      { label: 'Stok Durumu', icon: 'inventory_2', path: '/stok', permission: 'stock.view' },
      {
        label: 'Stok Hareketleri',
        icon: 'swap_vert',
        path: '/hareketler',
        permission: ['movements.view_team', 'movements.view_all'],
      },
      { label: 'Depolar', icon: 'warehouse', path: '/depolar', permission: 'warehouses.view' },
      { label: 'Raporlar', icon: 'bar_chart', path: '/raporlar', permission: 'reports.view' },
    ],
  },
  {
    title: 'Yönetim',
    items: [
      { label: 'Personeller', icon: 'group', path: '/personeller', permission: 'users.view' },
      { label: 'Roller ve Yetkiler', icon: 'shield_person', path: '/roller', permission: 'roles.view' },
    ],
  },
];

export const MENU: MenuItem[] = MENU_GROUPS.flatMap((g) => g.items);
