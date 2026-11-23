export interface Activity {
  key: string;
  title: string;
  photo: boolean;
}

export interface Rule {
  activities: Activity[];
  hours_needed: number;
  evidence_note: string | null;
}

export interface ModuleHours {
  module_id: string;
  code: string;
  title: string;
  hours_needed: number | null;
  hours: number;
  signed_hours: number;
}

export interface Chain {
  ok: boolean;
  entries: number;
  broken_at: number | null;
}

export interface Entry {
  id: string;
  module_id: string;
  activity_key: string;
  work_date: string;
  minutes: number;
  note: string | null;
  distance_m: number | null;
  location_ok: boolean | null;
  offline: boolean;
  status: 'submitted' | 'signed' | 'returned' | 'corrected';
  comment: string | null;
  seq: number;
  rechained: boolean;
  replaces_id: string | null;
}

export const entryState = {
  submitted: { label: 'Waiting for mentor', tone: 'warning' },
  signed: { label: 'Signed', tone: 'success' },
  returned: { label: 'Returned', tone: 'danger' },
  corrected: { label: 'Corrected', tone: 'neutral' },
} as const;

/** "4 h 30" from minutes. */
export function duration(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h} h ${String(m).padStart(2, '0')}` : `${h} h`;
}
