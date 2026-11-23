import type { Tone } from '@/components/ui';

export type CohortStatus = 'draft' | 'open' | 'closed' | 'cancelled';
export type StaffRole = 'facilitator' | 'assessor' | 'moderator';

export const STAFF_ROLES: { role: StaffRole; label: string }[] = [
  { role: 'facilitator', label: 'Facilitators' },
  { role: 'assessor', label: 'Assessors' },
  { role: 'moderator', label: 'Moderators' },
];

export const MODES: Record<string, string> = {
  contact: 'Contact',
  blended: 'Blended',
  online: 'Online',
  workplace: 'Workplace',
};

export const cohortState: Record<CohortStatus, { label: string; tone: Tone }> = {
  draft: { label: 'Planning', tone: 'neutral' },
  open: { label: 'Open', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

export interface CohortRow {
  id: string;
  name: string;
  qualification_id: string;
  centre_id: string;
  mode: string;
  capacity: number;
  status: CohortStatus;
  intake_on: string;
  planned_end_on: string;
  qualification: string | null;
  centre: string | null;
  at_risk: boolean;
}

export interface ReadinessItem {
  key: string;
  group: string;
  status: 'pass' | 'warn' | 'fail';
  title: string;
  detail: string;
  fix: string | null;
}

export interface ReadinessCheck {
  ok: boolean;
  counts: { pass: number; warn: number; fail: number };
  items: ReadinessItem[];
}

export interface CohortDetail extends Omit<CohortRow, 'qualification' | 'centre' | 'at_risk'> {
  notes: string | null;
  opened_at: string | null;
  qualification: { id: string; title: string; saqa_id: string | null } | null;
  centre: string | null;
  staff: { user_id: string; role: StaffRole; name: string | null }[];
  placements: {
    site_id: string;
    employer_id: string;
    mentor_id: string | null;
    planned_learners: number;
    site: string | null;
    employer: string | null;
    mentor: string | null;
  }[];
  readiness: ReadinessCheck;
}

export interface CohortOptions {
  people: Record<StaffRole, { user_id: string; name: string; ok: boolean; reason: string }[]>;
  sites: {
    id: string;
    name: string;
    employer_id: string;
    employer: string | null;
    approval_state: string;
    approved: boolean;
    free: number;
    mentors: { id: string; name: string }[];
  }[];
}

export const shortDate = (iso: string) =>
  new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
