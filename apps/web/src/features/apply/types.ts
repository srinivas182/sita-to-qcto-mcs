export interface Check {
  ok: boolean;
  required: boolean;
  message: string;
}

export interface ApplicationFile {
  id: string;
  kind: 'accreditation_letter' | 'cipc_certificate' | 'other';
  name: string;
  size: number;
  status: string;
}

export interface ApplicationData {
  id: string;
  reference: string;
  status: 'draft' | 'submitted' | 'info_requested' | 'approved' | 'declined';
  email_verified: boolean;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  legal_name: string;
  trading_name: string | null;
  registration_number: string | null;
  vat_number: string | null;
  website: string | null;
  sdp_number: string | null;
  accreditation_expires_on: string | null;
  scope: string[];
  centre_name: string | null;
  address_line1: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  preferred_code: string | null;
  package_id: string | null;
  interval: 'month' | 'year' | null;
  consented: boolean;
  message: string | null;
  submitted_at: string | null;
  files: ApplicationFile[];
  checks: Record<string, Check>;
  base_domain: string;
  // Reviewer only
  checklist?: { key: string; label: string; done: boolean }[];
  review_notes?: string | null;
  decided_at?: string | null;
  tenant_id?: string | null;
  ip?: string | null;
  created_at?: string;
}

export const provinces = [
  'Eastern Cape',
  'Free State',
  'Gauteng',
  'KwaZulu-Natal',
  'Limpopo',
  'Mpumalanga',
  'North West',
  'Northern Cape',
  'Western Cape',
] as const;
