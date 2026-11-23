export interface ClaimLearner {
  enrolment_id: string;
  user_id: string;
  name: string | null;
  amount_cents: number;
  disability_cents: number;
}

export interface ClaimQuery {
  id: string;
  enrolment_id: string | null;
  problem: string;
  raised_on: string;
  resolution: string | null;
  resolved_at: string | null;
}

export interface Claim {
  id: string;
  number: string;
  agreement_id: string;
  tranche_id: string;
  status: 'draft' | 'sent' | 'queried' | 'paid' | 'rejected' | 'void';
  learners: ClaimLearner[];
  amount_cents: number;
  sent_on: string | null;
  paid_on: string | null;
  paid_cents: number | null;
  proof_file_id: string | null;
  closing_note: string | null;
  created_at: string | null;
  queries: ClaimQuery[];
}

export const claimStatus: Record<
  Claim['status'],
  { label: string; tone: 'success' | 'info' | 'warning' | 'danger' | 'neutral' }
> = {
  draft: { label: 'Draft', tone: 'neutral' },
  sent: { label: 'Sent', tone: 'info' },
  queried: { label: 'Queried', tone: 'warning' },
  paid: { label: 'Paid', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
  void: { label: 'Cancelled', tone: 'neutral' },
};
