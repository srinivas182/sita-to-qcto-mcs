import type { Tone } from '@/components/ui';

export type ApprovalState = 'approved' | 'expiring' | 'expired' | 'not_approved';

export interface EmployerSummary {
  id: string;
  legal_name: string;
  trading_name: string | null;
  registration_number: string | null;
  sdl_number: string | null;
  seta: string | null;
  bbbee_level: string | null;
  sector: string | null;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  status: 'active' | 'inactive';
  notes: string | null;
}

export interface EmployerRow extends EmployerSummary {
  sites: number;
  mentors: number;
  approval: ApprovalState;
}

export interface Site {
  id: string;
  name: string;
  address_line1: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  latitude: number | null;
  longitude: number | null;
  approval_number: string | null;
  approval_body: string | null;
  approval_file_id: string | null;
  approved_on: string | null;
  approval_expires_on: string | null;
  approval_state: ApprovalState;
  mentor_ratio: number;
  ratio_overrides: Record<string, number>;
  qualifications: { id: string; title: string | null }[];
  status: 'active' | 'inactive';
}

export interface Mentor {
  id: string;
  user_id: string | null;
  name: string;
  email: string | null;
  phone: string | null;
  designation: string;
  years_experience: number;
  highest_qualification: string | null;
  qualification_nqf_level: number | null;
  site_ids: string[];
  active: boolean;
}

export interface EmployerDetail extends EmployerSummary {
  sites: Site[];
  mentors: Mentor[];
  members: { id: string; name: string; email: string | null }[];
  capacity: {
    site_id: string;
    qualification_id: string;
    title: string;
    mentors: number;
    ratio: number;
    capacity: number;
  }[];
  workplace_criteria: { qualification: string; code: string; title: string; criteria: string }[];
}

export const approvalState: Record<ApprovalState, { label: string; tone: Tone }> = {
  approved: { label: 'Approved', tone: 'success' },
  expiring: { label: 'Approval ending', tone: 'warning' },
  expired: { label: 'Approval expired', tone: 'danger' },
  not_approved: { label: 'Not approved yet', tone: 'neutral' },
};

export const PROVINCES = [
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

export const shortDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

/** Empty strings become null so optional fields clear rather than fail validation. */
export function clean<T extends Record<string, unknown>>(form: T): Record<string, unknown> {
  return Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
}
