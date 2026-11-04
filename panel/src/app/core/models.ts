export interface Page<T> {
  data: T[];
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface RoleRef {
  id: number;
  name: string;
  slug: string;
}

export interface WarehouseRef {
  id: number;
  name: string;
  code: string;
  type: WarehouseType;
}

export type WarehouseType = 'central' | 'branch' | 'field';

export interface Profile {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: RoleRef | null;
  manager: { id: number; name: string } | null;
  is_super_admin: boolean;
  permissions: string[];
  warehouses: WarehouseRef[];
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role_id: number | null;
  manager_id: number | null;
  is_active: boolean;
  last_login_at: string | null;
  role?: RoleRef | null;
  manager?: { id: number; name: string } | null;
  warehouses?: WarehouseRef[];
  direct_permissions?: string[];
  effective_permissions?: string[];
}

export interface Role extends RoleRef {
  description: string | null;
  is_system: boolean;
  users_count?: number;
  permissions: string[];
}

export interface PermissionGroup {
  page: string;
  label: string;
  permissions: { key: string; action: string; label: string }[];
}

export type Tracking = 'none' | 'lot' | 'serial';

export const TRACKING_LABELS: Record<Tracking, string> = {
  lot: 'Lot numaralı',
  serial: 'Seri numaralı (her birim tek)',
  none: 'Takipsiz',
};

export interface Product {
  id: number;
  name: string;
  code: string;
  barcode: string | null;
  /** Karekoddaki ürün numarası, 14 hane */
  gtin: string | null;
  tracking: Tracking;
  category: string | null;
  brand: string | null;
  unit: string;
  description: string | null;
  min_stock_level: number;
  is_active: boolean;
  total_stock?: string | number | null;
  stocks?: Stock[];
}

export interface Warehouse extends WarehouseRef {
  address: string | null;
  is_active: boolean;
  users?: { id: number; name: string }[];
}

/** Bir depodaki bir ürünün belirli lot/seri/SKT'li miktarı */
export interface Stock {
  id: number;
  warehouse_id: number;
  product_id: number;
  lot_number: string | null;
  serial_number: string | null;
  /** YYYY-MM-DD */
  expiry_date: string | null;
  quantity: string;
  product?: Pick<Product, 'id' | 'name' | 'code' | 'unit' | 'min_stock_level' | 'category' | 'barcode' | 'gtin' | 'tracking'>;
  warehouse?: WarehouseRef;
}

export type HospitalType = 'public' | 'university' | 'private' | 'training_research' | 'city' | 'other';

export interface Hospital {
  id: number;
  name: string;
  code: string | null;
  type: HospitalType;
  city: string | null;
  district: string | null;
  address: string | null;
  notes: string | null;
  is_active: boolean;
  deliveries_30d?: number;
  last_delivery_at?: string | null;
}

/** Sıra, formlarda ve filtrelerde görünen sıradır. */
export const HOSPITAL_LABELS: Record<HospitalType, string> = {
  public: 'Devlet hastanesi',
  university: 'Üniversite hastanesi',
  private: 'Özel hastane',
  training_research: 'Eğitim ve araştırma hastanesi',
  city: 'Şehir hastanesi',
  other: 'Diğer',
};

export const HOSPITAL_TYPES = Object.keys(HOSPITAL_LABELS) as HospitalType[];

export type MovementType = 'in' | 'usage' | 'transfer_out' | 'transfer_in' | 'adjustment';

export interface Movement {
  id: number;
  batch_uuid: string;
  type: MovementType;
  quantity: string;
  balance_after: string;
  lot_number: string | null;
  serial_number: string | null;
  expiry_date: string | null;
  reference: string | null;
  note: string | null;
  latitude: string | null;
  longitude: string | null;
  created_at: string;
  product?: { id: number; name: string; code: string; unit: string };
  warehouse?: { id: number; name: string; code?: string };
  user?: { id: number; name: string };
  hospital?: { id: number; name: string; city?: string | null } | null;
}

export const MOVEMENT_LABELS: Record<MovementType, string> = {
  in: 'Giriş',
  usage: 'Hastaneye teslim / kullanım',
  transfer_out: 'Transfer (çıkış)',
  transfer_in: 'Transfer (giriş)',
  adjustment: 'Sayım düzeltmesi',
};

export const WAREHOUSE_LABELS: Record<WarehouseType, string> = {
  central: 'Merkez depo',
  branch: 'Şube deposu',
  field: 'Personel çantası / araç',
};
