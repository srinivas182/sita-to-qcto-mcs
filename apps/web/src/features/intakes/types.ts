import type { Tone } from '@/components/ui';

export interface Question {
  key: string;
  label: string;
  type: 'text' | 'yesno' | 'choice';
  options?: string[];
  required?: boolean;
}

export interface Intake {
  id: string;
  cohort_id: string;
  slug: string;
  title: string;
  description: string | null;
  places: number;
  min_age: number | null;
  max_age: number | null;
  questions: Question[] | null;
  document_types: string[] | null;
  status: 'draft' | 'open' | 'closed';
  opens_on: string;
  closes_on: string;
  accepting: boolean;
  url: string;
  qr?: string;
  cohort?: string | null;
  qualification?: string | null;
  counts?: Record<string, number>;
}

export type AppStatus =
  'submitted' | 'shortlisted' | 'waitlisted' | 'offered' | 'accepted' | 'enrolled' | 'declined' | 'withdrawn';

export interface ApplicationRow {
  id: string;
  reference: string;
  status: AppStatus;
  answers: Record<string, string | boolean> | null;
  reason: string | null;
  waitlist_position: number | null;
  source: string;
  submitted_at: string;
  documents?: { accepted: number; required: number };
  applicant: {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    id_number: string | null;
    id_type: string | null;
    age: number | null;
  } | null;
}

export const appState: Record<AppStatus, { label: string; tone: Tone }> = {
  submitted: { label: 'New', tone: 'info' },
  shortlisted: { label: 'Shortlisted', tone: 'primary' },
  waitlisted: { label: 'Waiting list', tone: 'warning' },
  offered: { label: 'Offered', tone: 'primary' },
  accepted: { label: 'Accepted', tone: 'success' },
  enrolled: { label: 'Enrolled', tone: 'success' },
  declined: { label: 'Not successful', tone: 'neutral' },
  withdrawn: { label: 'Withdrawn', tone: 'neutral' },
};

export const intakeState: Record<Intake['status'], { label: string; tone: Tone }> = {
  draft: { label: 'Not published', tone: 'neutral' },
  open: { label: 'Taking applications', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
};

export const day = (iso: string) =>
  new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
