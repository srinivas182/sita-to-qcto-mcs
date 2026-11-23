import { useQuery } from '@tanstack/react-query';
import * as Location from 'expo-location';
import { Directory, File, Paths } from 'expo-file-system';
import { planPack, type Held, type PackItem } from '@/core/offlinePack';
import { ApiError, api, json } from './api';
import { outbox } from './outbox';
import { getTenantCode, session } from './session';

export interface ClassSession {
  id: string;
  cohort_id: string;
  kind: 'knowledge' | 'practical';
  title: string | null;
  status: 'scheduled' | 'cancelled';
  cancel_reason: string | null;
  starts_at: string;
  ends_at: string;
  room: { name: string } | null;
  venue: string | null;
  facilitator: { name: string } | null;
  cohort?: string | null;
  teaching?: boolean;
}

export interface Dashboard {
  courses: {
    enrolment_id: string;
    cohort: string;
    title: string | null;
    attendance: { pct: number | null; held: number; attended: number; at_risk: boolean };
    materials: { total: number; done: number };
    tasks: { total: number; passed: number; waiting: number };
  }[];
  today: ClassSession[];
  registers_to_sign: number;
}

export interface CourseMaterial {
  id: string;
  module_id: string | null;
  title: string;
  description: string | null;
  kind: 'document' | 'video' | 'image' | 'link';
  url: string | null;
  version: number;
  size: number | null;
  mime: string | null;
  low_size: number | null;
  completed: boolean;
}

export interface LearnerTask {
  id: string;
  module_id: string | null;
  title: string;
  instructions: string | null;
  evidence: boolean;
  evidence_hint: string | null;
  pass_pct: number;
  attempts_allowed: number;
  out_of: number;
  questions: { id: string; type: 'single' | 'multi' | 'short'; text: string; options?: string[]; marks: number }[];
  attempts: { id: string; number: number; status: 'submitted' | 'passed' | 'not_yet'; score_pct: number | null; feedback: string | null }[];
}

export interface MyRegister {
  id: string;
  cohort: string | null;
  month: string;
  status: string;
  role: 'learner' | 'facilitator';
  my_status: 'pending' | 'signed' | 'disputed';
  attended: number | null;
  held: number | null;
  pct: number | null;
}

const get = <T,>(path: string) => json<{ data: T }>(api.GET(path as never)).then((r) => r.data);

export const useDashboard = (enabled = true) => useQuery({ queryKey: ['dashboard'], queryFn: () => get<Dashboard>('/me/dashboard'), enabled });
export const useMyTimetable = (enabled = true) => useQuery({ queryKey: ['my-timetable'], queryFn: () => get<ClassSession[]>('/me/timetable'), enabled });
export const useCourseMaterials = (enrolmentId: string, enabled = true) =>
  useQuery({ queryKey: ['course-materials', enrolmentId], queryFn: () => get<{ modules: { id: string; code: string; title: string }[]; materials: CourseMaterial[] }>(`/me/courses/${enrolmentId}/materials`), enabled });
export const useCourseTasks = (enrolmentId: string, enabled = true) =>
  useQuery({ queryKey: ['course-tasks', enrolmentId], queryFn: () => get<LearnerTask[]>(`/me/courses/${enrolmentId}/tasks`), enabled });
export const useMyRegisters = (enabled = true) => useQuery({ queryKey: ['my-registers'], queryFn: () => get<MyRegister[]>('/me/registers'), enabled });

/** The phone's position once, for the distance check. Never fails the check-in. */
async function place(): Promise<{ latitude?: number; longitude?: number; accuracy?: number; mocked?: boolean }> {
  try {
    const perm = await Location.requestForegroundPermissionsAsync();
    if (!perm.granted) return {};
    const p = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
    return { latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy ?? undefined, mocked: p.mocked === true };
  } catch {
    return {};
  }
}

export type CheckInResult =
  | { kind: 'done'; action: string; status: string; minutesLate: number; review: string | null; title: string | null }
  | { kind: 'saved' }
  | { kind: 'refused'; message: string };

/**
 * Check in with a scanned code. Online it is sent at once; offline (or if sending fails on
 * the network) it is kept in the outbox with the time it was scanned, and sent later.
 */
export async function checkIn(token: string, online: boolean): Promise<CheckInResult> {
  const scannedAt = new Date().toISOString();
  const where = await place();
  if (online) {
    try {
      const r = await json<{ data: { action: string; status: string; minutes_late: number; needs_review: boolean; review_reason: string | null; session: { title: string | null } } }>(
        api.POST('/me/check-ins' as never, { body: { token, ...where } } as never),
      );
      return { kind: 'done', action: r.data.action, status: r.data.status, minutesLate: r.data.minutes_late, review: r.data.needs_review ? r.data.review_reason : null, title: r.data.session.title };
    } catch (e) {
      if (e instanceof ApiError) return { kind: 'refused', message: Object.values(e.fields)[0]?.[0] ?? e.message };
      // A network failure: fall through and keep it for later.
    }
  }
  await outbox.enqueue({ stream: 'attendance', method: 'POST', path: '/me/check-ins', body: { token, offline: true, scanned_at: scannedAt, ...where } });
  return { kind: 'saved' };
}

// ── Offline course materials ───────────────────────────────────────────────────────
const dir = () => new Directory(Paths.document, 'course');
const indexFile = () => new File(dir(), 'index.json');

export function heldMaterials(): Record<string, Held> {
  try {
    const f = indexFile();
    return f.exists ? (JSON.parse(f.textSync()) as Record<string, Held>) : {};
  } catch {
    return {};
  }
}

function saveIndex(held: Record<string, Held>): void {
  const d = dir();
  if (!d.exists) d.create({ intermediates: true });
  indexFile().write(JSON.stringify(held));
}

export async function packFor(enrolmentId: string): Promise<{ items: PackItem[]; total_bytes: number }> {
  return get<{ items: PackItem[]; total_bytes: number }>(`/me/courses/${enrolmentId}/offline-pack`);
}

/** Bring the phone up to date with the pack: fetch new and changed items, drop removed ones. */
export async function syncPack(enrolmentId: string, onProgress?: (done: number, of: number) => void): Promise<number> {
  const pack = await packFor(enrolmentId);
  const held = heldMaterials();
  const plan = planPack(pack.items, held);
  for (const id of plan.remove) {
    try {
      new File(held[id]!.path).delete();
    } catch {
      // already gone
    }
    delete held[id];
  }
  const token = await session.accessToken().catch(() => null);
  const tenant = getTenantCode();
  let done = 0;
  for (const item of plan.download) {
    const link = await get<{ url: string; via: string }>(`/me/materials/${item.id}/download${item.low ? '?low=1' : ''}`);
    const d = dir();
    if (!d.exists) d.create({ intermediates: true });
    const target = new File(d, `${item.id}-v${item.version}`);
    if (target.exists) target.delete();
    const headers: Record<string, string> = link.via === 'api' ? { ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { 'X-Tenant': tenant } : {}) } : {};
    await File.downloadFileAsync(link.url, target, { headers });
    if (held[item.id] && held[item.id]!.path !== target.uri) {
      try {
        new File(held[item.id]!.path).delete();
      } catch {
        // ignore
      }
    }
    held[item.id] = { version: item.version, low: item.low, path: target.uri };
    saveIndex(held);
    onProgress?.(++done, plan.download.length);
  }
  saveIndex(held);
  return plan.download.length;
}
