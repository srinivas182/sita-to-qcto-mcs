export interface TenantRow {
  id: string;
  code: string;
  legal_name: string;
  trading_name: string;
  registration_number: string | null;
  status: 'pending' | 'verified' | 'active' | 'suspended';
  cell_id: string;
  package_code: string | null;
  suspension_reason: string | null;
  created_at: string | null;
  centres_count?: number;
  domains?: Array<{ id: string; hostname: string; is_primary: boolean; dns_status: string; ssl_status: string }>;
}

export interface Paginated<T> {
  data: T[];
  meta?: { total: number; current_page?: number; last_page?: number };
}
