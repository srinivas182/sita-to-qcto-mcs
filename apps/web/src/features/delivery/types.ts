import type { Tone } from '@/components/ui';

export type AttendanceStatus = 'present' | 'late' | 'left_early' | 'absent' | 'excused';

export interface AttendanceMark {
  id: string;
  status: AttendanceStatus;
  label: string;
  method: 'qr' | 'facilitator' | 'note';
  offline: boolean;
  checked_in_at: string | null;
  checked_out_at: string | null;
  minutes_late: number;
  distance_m: number | null;
  location_ok: boolean | null;
  needs_review: boolean;
  review_reason: string | null;
  reason: string | null;
  note_file_id: string | null;
}

export const attendanceTone: Record<AttendanceStatus, Tone> = {
  present: 'success',
  late: 'warning',
  left_early: 'warning',
  absent: 'danger',
  excused: 'neutral',
};

export const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: 'Present',
  late: 'Late',
  left_early: 'Left early',
  absent: 'Absent',
  excused: 'Excused',
};
