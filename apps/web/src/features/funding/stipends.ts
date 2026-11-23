export interface StipendRule {
  stipend_cents: number | null;
  full_pct: number;
  hold_pct: number;
  prorata: boolean;
}

export interface StipendRun {
  id: string;
  agreement_id: string;
  month: string;
  status: 'draft' | 'confirmed' | 'paid';
  total_cents: number;
  confirmed_at: string | null;
  paid_on: string | null;
  proof_file_id: string | null;
}

export interface Bank {
  holder_name: string;
  bank: string;
  bank_name: string;
  branch_code: string;
  account: string;
  account_type: string;
  proof_file_id: string | null;
}

export interface StipendLine {
  id: string;
  enrolment_id: string;
  name: string | null;
  attendance_pct: number | null;
  proposed_cents: number;
  amount_cents: number;
  status: 'pay' | 'hold';
  reason: string | null;
  override_reason: string | null;
  bank: Bank | null;
}

export interface BankChoice {
  key: string;
  name: string;
  branch_code: string;
}

/** "2027-03" as "March 2027". */
export function monthName(ym: string): string {
  const [y, m] = ym.split('-').map(Number);
  return new Date(Date.UTC(y ?? 2000, (m ?? 1) - 1, 1)).toLocaleDateString('en-ZA', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  });
}
