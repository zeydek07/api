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
  /** Hastane lokasyonlarında tam yol: "Ortopedi › Ameliyathane 2 › Dolap A" */
  path?: string | null;
  code: string;
  type: WarehouseType;
  hospital_id?: number | null;
  /** Konsinye lokasyonuysa ait olduğu hastane */
  hospital?: { id: number; name: string } | null;
}

export type WarehouseType = 'central' | 'branch' | 'field' | 'consignment';

/** "Kartal Devlet Hastanesi · Ana konsinye deposu" ya da "Merkez Depo" */
export function placeName(
  w: WarehouseRef | { name: string; path?: string | null; hospital?: { name: string } | null } | null | undefined,
): string {
  if (!w) return '';
  const name = w.path || w.name;
  return w.hospital ? `${w.hospital.name} · ${name}` : name;
}

/** Lokasyonun tam yolu (hastane lokasyonları) ya da adı */
export function locName(l: { name: string; path?: string | null } | null | undefined): string {
  return l ? l.path || l.name : '';
}

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
  responsible_user_id?: number | null;
  /** Hastaneye bakan firma personeli (mümessil) */
  responsible?: { id: number; name: string } | null;
  /** Hastanedeki konsinye ürün adedi */
  consignment_units?: string | number | null;
  deliveries_30d?: number;
  last_delivery_at?: string | null;
}

/** Hastanede konsinye ürünlerin durduğu yer */
export type LocationLevel = 'bolum' | 'oda' | 'depo' | 'dolap' | 'raf';

/** Seviye sırası: alt lokasyonun sırası üstündekinden büyük olmalı */
export const LOCATION_LEVELS: Record<LocationLevel, { label: string; rank: number; icon: string }> = {
  bolum: { label: 'Bölüm', rank: 1, icon: 'apartment' },
  oda: { label: 'Oda', rank: 2, icon: 'meeting_room' },
  depo: { label: 'Depo', rank: 2, icon: 'warehouse' },
  dolap: { label: 'Dolap', rank: 3, icon: 'kitchen' },
  raf: { label: 'Raf', rank: 4, icon: 'shelves' },
};

export interface ConsignmentLocation {
  id: number;
  name: string;
  /** "Ortopedi › Ameliyathane 2 › Dolap A" */
  path?: string | null;
  code: string;
  hospital_id: number;
  parent_id?: number | null;
  level?: LocationLevel | null;
  depth?: number;
  is_active: boolean;
  /** Lokasyonun kendi stoku */
  units?: string | number | null;
  /** Alt lokasyonlarla birlikte */
  total_units?: number;
  has_children?: boolean;
}

/** Lokasyonun kendisi ve tüm alt lokasyonlarının id'leri */
export function subtreeIds(rootId: number, all: ConsignmentLocation[]): Set<number> {
  const ids = new Set([rootId]);
  let added = true;
  while (added) {
    added = false;
    for (const l of all) {
      if (l.parent_id && ids.has(l.parent_id) && !ids.has(l.id)) {
        ids.add(l.id);
        added = true;
      }
    }
  }
  return ids;
}

/** Hastane personeli: teslim alan / muhatap (firma personeli değil) */
export interface HospitalContact {
  id: number;
  hospital_id: number;
  location_id: number | null;
  location?: { id: number; name: string; path?: string | null } | null;
  name: string;
  title: string | null;
  phone: string | null;
  email: string | null;
  is_active: boolean;
}

export type DeliveryType = 'delivery' | 'return';

export interface Delivery {
  id: number;
  number: string;
  type: DeliveryType;
  status: 'completed' | 'cancelled';
  hospital_id: number;
  location_id: number;
  vehicle_id: number;
  contact_id: number | null;
  receiver_name: string;
  receiver_title: string | null;
  user_id: number;
  delivered_at: string;
  device_time: string | null;
  latitude: string | null;
  longitude: string | null;
  location_accuracy: number | null;
  location_error: string | null;
  location_status: 'ok' | 'missing';
  note: string | null;
  has_signature: boolean;
  has_photo: boolean;
  cancelled_at: string | null;
  cancel_reason: string | null;
  lines_count?: number;
  units?: string | number | null;
  hospital?: { id: number; name: string; code?: string | null; city?: string | null; district?: string | null; address?: string | null };
  location?: { id: number; name: string; path?: string | null; code?: string };
  vehicle?: { id: number; name: string; code?: string };
  contact?: { id: number; name: string; title: string | null; phone: string | null; email: string | null } | null;
  user?: { id: number; name: string; email?: string; phone?: string | null };
  canceller?: { id: number; name: string } | null;
  lines?: Movement[];
}

export const DELIVERY_LABELS: Record<DeliveryType, string> = {
  delivery: 'Konsinye teslim',
  return: 'Geri alma',
};

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

export type MovementType =
  | 'in'
  | 'usage'
  | 'transfer_out'
  | 'transfer_in'
  | 'adjustment'
  | 'consign_out'
  | 'consign_in'
  | 'return_out'
  | 'return_in';

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
  warehouse?: { id: number; name: string; path?: string | null; code?: string; hospital?: { id: number; name: string } | null };
  delivery?: { id: number; number: string; type: DeliveryType } | null;
  user?: { id: number; name: string };
  hospital?: { id: number; name: string; city?: string | null } | null;
}

export const MOVEMENT_LABELS: Record<MovementType, string> = {
  in: 'Giriş',
  usage: 'Hastaneye teslim / kullanım',
  transfer_out: 'Transfer (çıkış)',
  transfer_in: 'Transfer (giriş)',
  adjustment: 'Sayım düzeltmesi',
  consign_out: 'Konsinye teslim (araçtan)',
  consign_in: 'Konsinye teslim (hastaneye)',
  return_out: 'Geri alma (hastaneden)',
  return_in: 'Geri alma (araca)',
};

export const WAREHOUSE_LABELS: Record<WarehouseType, string> = {
  central: 'Merkez depo',
  branch: 'Şube deposu',
  field: 'Personel çantası / araç',
  consignment: 'Hastane konsinye lokasyonu',
};
