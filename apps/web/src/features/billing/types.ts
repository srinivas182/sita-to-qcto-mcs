export interface Package {
  id: string;
  code: string;
  name: string;
  description: string | null;
  features: string[];
  base_fee_cents: number;
  annual_fee_cents: number;
  per_learner_cents: number;
  included_learners: number;
  max_learners: number | null;
  max_centres: number | null;
  annual_discount_percent: number;
  trial_days: number;
  is_example: boolean;
  active: boolean;
  public: boolean;
  sort: number;
}

export type PlanStatus = 'trialing' | 'active' | 'past_due' | 'read_only' | 'suspended' | 'cancelled';

export interface Subscription {
  id: string;
  status: PlanStatus;
  interval: 'month' | 'year';
  collection: 'auto' | 'invoice';
  package: Package;
  pending_package: Package | null;
  trial_ends_at: string | null;
  current_period_start: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  cancelled_at: string | null;
  past_due_since: string | null;
}

export interface Line {
  kind: string;
  description: string;
  quantity: number;
  unit_cents: number;
  amount_cents: number;
}

export interface Invoice {
  id: string;
  number: string;
  status: 'open' | 'overdue' | 'paid' | 'void';
  subtotal_cents: number;
  vat_rate: number;
  vat_cents: number;
  total_cents: number;
  amount_paid_cents: number;
  amount_credited_cents: number;
  amount_due_cents: number;
  eft_reference: string;
  period_start: string | null;
  period_end: string | null;
  issued_at: string;
  due_at: string;
  paid_at: string | null;
  recipient: { legal_name: string | null };
  tenant?: { id: string; name: string | null };
  lines?: Line[];
}

export interface Gateway {
  driver: string;
  label: string;
  capabilities: string[];
  mode: 'sandbox' | 'live';
}

export interface Profile {
  legal_name: string;
  vat_number?: string | null;
  registration_number?: string | null;
  address_line1?: string | null;
  address_line2?: string | null;
  city?: string | null;
  province?: string | null;
  postal_code?: string | null;
  email: string;
  purchase_order?: string | null;
}

export interface Billing {
  subscription: Subscription | null;
  estimate: {
    subtotal_cents: number;
    vat_cents: number;
    total_cents: number;
    vat_rate: number;
    date: string;
    lines: Line[];
    active_learners: number;
  } | null;
  profile: Profile | null;
  payment_method: {
    id: string;
    driver: string;
    brand: string | null;
    last4: string | null;
    exp_month: number | null;
    exp_year: number | null;
    expired: boolean;
  } | null;
  open_invoices: Invoice[];
  packages: Package[];
  gateways: Gateway[];
}

export const statusCopy: Record<
  PlanStatus,
  { label: string; tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }
> = {
  trialing: { label: 'Trial', tone: 'info' },
  active: { label: 'Active', tone: 'success' },
  past_due: { label: 'Payment overdue', tone: 'warning' },
  read_only: { label: 'Read-only', tone: 'danger' },
  suspended: { label: 'Suspended', tone: 'danger' },
  cancelled: { label: 'Ended', tone: 'neutral' },
};
