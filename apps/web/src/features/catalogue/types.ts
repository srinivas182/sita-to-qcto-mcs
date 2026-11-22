export type Enrolment = 'open' | 'closing' | 'closed' | 'ended' | 'draft' | 'withdrawn';

export interface QualificationSummary {
  id: string;
  type: string;
  type_label: string;
  legacy: boolean;
  saqa_id: string | null;
  curriculum_code: string | null;
  title: string;
  nqf_level: number;
  credits: number;
  quality_partner: string | null;
  assessment_partner: string | null;
  eisa: boolean;
  status: 'draft' | 'published' | 'withdrawn';
  registration_end: string | null;
  last_enrolment_on: string | null;
  last_achievement_on: string | null;
  days_to_last_enrolment: number | null;
  enrolment: Enrolment;
  successor_ids: string[];
  version: number;
  modules?: number;
  offered?: boolean;
}

export interface Module {
  id: string;
  kind: 'KM' | 'PM' | 'WM' | 'US';
  kind_label: string;
  group: string;
  code: string;
  title: string;
  nqf_level: number | null;
  credits: number;
  workplace_criteria: string | null;
}

export interface QualificationDetail extends Omit<QualificationSummary, 'modules'> {
  credit_rules: { fundamental?: number; core?: number; elective_min?: number } | null;
  source_url?: string | null;
  notes?: string | null;
  modules: Module[];
  successors: { id: string; title: string; saqa_id: string | null; status: string }[] | QualificationSummary[];
  checks?: { errors: string[]; warnings: string[]; totals: Record<string, number> };
}

export const enrolmentBadge: Record<
  Enrolment,
  { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; label: (d: number | null) => string }
> = {
  open: { tone: 'success', label: () => 'Open for enrolment' },
  closing: { tone: 'warning', label: (d) => `Enrolment closes in ${d} days` },
  closed: { tone: 'danger', label: () => 'Closed to new learners' },
  ended: { tone: 'neutral', label: () => 'Ended' },
  draft: { tone: 'neutral', label: () => 'Draft' },
  withdrawn: { tone: 'neutral', label: () => 'Withdrawn' },
};
