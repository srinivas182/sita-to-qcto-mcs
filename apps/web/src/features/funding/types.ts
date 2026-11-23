export interface Funder {
  id: string;
  name: string;
  kind: 'seta' | 'employer' | 'other';
  seta_code: string | null;
  template: string;
  contact_email: string | null;
}

export interface FundersResponse {
  data: Funder[];
  kinds: string[];
  templates: string[];
  milestones: Record<string, string>;
}

export interface Milestone {
  key: string;
  months?: number;
  label?: string;
}

export interface Evidence {
  title: string;
  file_id: string | null;
}

export interface Tranche {
  id: string;
  number: number;
  share_pct: number;
  due_on: string | null;
  milestones: Milestone[];
  evidence: Evidence[];
  evidence_missing: number;
  per_learner_cents: number;
  learners_met: number;
  learners_total: number;
  claimable_cents: number;
  ready: boolean;
}

export interface Agreement {
  id: string;
  funder_id: string;
  cohort_id: string;
  reference: string;
  learners: number;
  amount_per_learner_cents: number;
  funding_kind: 'full' | 'co';
  disability_allowance_cents: number | null;
  starts_on: string;
  ends_on: string;
  moa_file_id: string | null;
  status: string;
  total_cents: number;
  funder: Funder | null;
  active_learners: number;
}

export interface ProgressLearner {
  grant_learner_id: string;
  enrolment_id: string;
  name: string | null;
  status: 'active' | 'removed';
  disabled: boolean;
  registers: number;
  tranches: Record<string, { met: boolean; blockers: string[] }>;
}

export interface TrancheForm {
  share_pct: number | string;
  due_on: string | null;
  milestones: Milestone[];
  evidence: Evidence[];
}

export interface AgreementDetail {
  agreement: Agreement;
  tranches: Tranche[];
  learners: ProgressLearner[];
  form: { enrolment_ids: string[]; disabled_ids: string[]; tranches: TrancheForm[] };
}

export interface Candidate {
  enrolment_id: string;
  name: string | null;
  funding: string | null;
  agreement_ids: string[];
}
