import { Button } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

export type Method = 'test' | 'observation' | 'project' | 'assignment' | 'logbook';

export interface Criterion {
  key: string;
  text: string;
}

export interface Instrument {
  id: string;
  module_id?: string;
  method: Method;
  title: string;
  criteria: Criterion[];
  pass_mark: number | null;
  instructions: string | null;
  task_id?: string | null;
}

/** The learner's attempts at the online task linked to an instrument. */
export interface OnlineAttempt {
  task_title: string;
  attempts: number;
  pass_pct: number;
  latest: { number: number; score_pct: number | null; status: string; submitted_at: string } | null;
}

export interface ResultRow {
  id: string;
  enrolment_id: string;
  module_id: string;
  instrument_id: string;
  attempt: number;
  outcome: 'competent' | 'nyc';
  score: number | null;
  criteria: { key: string; met: boolean; comment: string | null }[];
  file_ids: string[];
  feedback: string | null;
  remediation: string | null;
  assessed_on: string;
  decided_at: string;
  status: string;
  intact?: boolean;
  can_appeal?: boolean;
  appeal_until?: string;
  appeal?: {
    id: string;
    status: 'open' | 'upheld' | 'overturned';
    decide_by: string;
    decision_note: string | null;
  } | null;
}

export type ModuleState = 'competent' | 'nyc' | 'in_progress' | 'not_assessed' | 'no_assessment';

export interface ModuleResults {
  module_id: string;
  code: string;
  title: string;
  kind: 'KM' | 'PM' | 'WM';
  credits: number;
  state: ModuleState;
  instruments: { instrument_id: string; title: string; method: Method; attempts: number; latest: ResultRow | null }[];
}

export const methodLabel: Record<Method, string> = {
  test: 'Test',
  observation: 'Observation',
  project: 'Project',
  assignment: 'Assignment',
  logbook: 'Workplace logbook',
};

export const stateBadge = {
  competent: { label: 'Competent', tone: 'success' },
  nyc: { label: 'Not yet competent', tone: 'danger' },
  in_progress: { label: 'In progress', tone: 'warning' },
  not_assessed: { label: 'Not assessed', tone: 'neutral' },
  no_assessment: { label: 'No assessment set', tone: 'neutral' },
} as const;

/** "Evidence 1", "Evidence 2" buttons opening the files (signed links). */
export function photoLinks(fileIds: string[], onError: (m: string) => void) {
  if (!fileIds.length) return null;
  const open = (id: string) =>
    void call<{ url: string | null }>('GET', `/files/${id}/download`)
      .then((r) => r.url && window.open(r.url, '_blank', 'noopener'))
      .catch((e: unknown) => onError(errorMessage(e)));
  return (
    <span className="flex flex-wrap gap-2">
      {fileIds.map((f, n) => (
        <Button key={f} size="sm" variant="ghost" onClick={() => open(f)}>
          Evidence {n + 1}
        </Button>
      ))}
    </span>
  );
}
