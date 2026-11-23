import { useQuery } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { Directory, File, Paths } from 'expo-file-system';
import { entryHash, localDate, localIso } from '@/core/entryHash';
import { chainHead, processQueue, type QueuedEntry, type QueuedPhoto } from '@/core/logbookQueue';
import { api, json } from './api';
import { place } from './delivery';
import { uploadPhoto } from './enrolment';
import { API_BASE_URL } from './config';
import { randomId } from './random';
import { getTenantCode, session } from './session';

export interface Activity {
  key: string;
  title: string;
  photo: boolean;
}

export interface LogbookModule {
  id: string;
  code: string;
  title: string;
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

export interface LogbookEntry {
  id: string;
  client_id: string;
  module_id: string;
  activity_key: string;
  work_date: string;
  minutes: number;
  note: string | null;
  file_ids?: string[];
  status: 'submitted' | 'signed' | 'returned' | 'corrected';
  comment: string | null;
  distance_m: number | null;
  location_ok: boolean | null;
  offline: boolean;
}

export interface MyLogbook {
  placements: { id: string; starts_on: string; ends_on: string | null; site: string | null; mentor: string | null; cohort: string | null }[];
  modules: LogbookModule[];
  hours: ModuleHours[];
  last_hash: string | null;
  entries: LogbookEntry[];
  weeks: { id: string; week_start: string; status: 'open' | 'signed' | 'returned'; comment: string | null }[];
}

// ── Local storage: the last logbook seen (so the form works offline) and the waiting entries ──
const dir = () => new Directory(Paths.document, 'logbook');
const file = (name: string) => new File(dir(), name);

function readJson<T>(name: string, fallback: T): T {
  try {
    const f = file(name);
    return f.exists ? (JSON.parse(f.textSync()) as T) : fallback;
  } catch {
    return fallback;
  }
}

function writeJson(name: string, value: unknown): void {
  const d = dir();
  if (!d.exists) d.create({ intermediates: true });
  file(name).write(JSON.stringify(value));
}

export const waiting = (): QueuedEntry[] => readJson<QueuedEntry[]>('queue.json', []);
const saveQueue = (q: QueuedEntry[]) => writeJson('queue.json', q);
const cached = (): MyLogbook | null => readJson<MyLogbook | null>('cache.json', null);

/** The learner's logbook; offline it falls back to the copy last seen. */
export function useMyLogbook(online: boolean) {
  return useQuery({
    queryKey: ['my-logbook', online],
    queryFn: async () => {
      if (!online) {
        const c = cached();
        if (c) return c;
      }
      const data = await json<{ data: MyLogbook }>(api.GET('/me/logbook' as never)).then((r) => r.data);
      writeJson('cache.json', data);
      return data;
    },
  });
}

function hex(buf: ArrayBuffer): string {
  return Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Keep the photo in the app's own folder (the camera's copy may be cleared) and fingerprint it. */
async function keepPhoto(uri: string, clientId: string, n: number): Promise<QueuedPhoto> {
  const d = dir();
  if (!d.exists) d.create({ intermediates: true });
  const target = new File(d, `${clientId}-${n}.jpg`);
  if (target.exists) target.delete();
  new File(uri).copy(target);
  const bytes = await target.bytes();
  return { uri: target.uri, hash: hex(await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, bytes)) };
}

export interface NewEntry {
  moduleId: string;
  activity: string;
  date: string;
  minutes: number;
  note: string;
  photos: string[]; // camera URIs
  replaces?: string; // the id of an entry to correct
}

/**
 * Write an entry on the phone: stamp the time and place, fingerprint the photos, chain it to
 * the previous entry, and keep it until it can be sent. Works with no signal.
 */
export async function writeEntry(input: NewEntry, serverLast: string | null): Promise<QueuedEntry> {
  const clientId = randomId();
  const photos: QueuedPhoto[] = [];
  for (const [n, uri] of input.photos.entries()) photos.push(await keepPhoto(uri, clientId, n));
  const where = await place();
  const queue = waiting();
  const prev = chainHead(queue, serverLast);
  const deviceTime = localIso(new Date());
  const note = input.note.trim() || null;
  const hash = await entryHash(
    { prev, clientId, moduleId: input.moduleId, activity: input.activity, date: input.date, minutes: input.minutes, note, photoHashes: photos.map((p) => p.hash), deviceTime },
    (s) => Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, s),
  );
  const item: QueuedEntry = {
    clientId,
    path: input.replaces ? `/me/logbook/${input.replaces}/correct` : '/me/logbook',
    body: {
      client_id: clientId, module_id: input.moduleId, activity_key: input.activity, work_date: input.date, minutes: input.minutes, note,
      photo_hashes: photos.map((p) => p.hash), device_time: deviceTime, prev_hash: prev, hash, offline: true,
      latitude: where.latitude, longitude: where.longitude, accuracy: where.accuracy,
    },
    photos,
    createdAt: Date.now(),
    status: 'pending',
    error: null,
  };
  saveQueue([...queue, item]);
  return item;
}

async function post(path: string, body: Record<string, unknown>): Promise<{ status: number; body?: unknown }> {
  const token = await session.accessToken().catch(() => null);
  if (!token) return { status: 0 };
  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}`, ...(getTenantCode() ? { 'X-Tenant': getTenantCode()! } : {}) },
    body: JSON.stringify({ ...body, offline: body.offline ?? true }),
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

let running: Promise<number> | null = null;

/** Send waiting entries (photos first). Returns how many are still waiting. */
export function syncLogbook(): Promise<number> {
  running ??= (async () => {
    const queue = waiting();
    if (queue.every((q) => q.status === 'conflict')) return queue.length;
    const r = await processQueue(queue, { upload: (p) => uploadPhoto('logbook_photo', p.uri, p.uri.split('/').pop() ?? 'photo.jpg'), post });
    const sentIds = new Set(queue.map((q) => q.clientId).filter((id) => !r.queue.some((q) => q.clientId === id)));
    for (const q of queue) {
      if (!sentIds.has(q.clientId)) continue;
      for (const p of q.photos) {
        try {
          new File(p.uri).delete();
        } catch {
          // already gone
        }
      }
    }
    saveQueue(r.queue);
    return r.queue.length;
  })().finally(() => {
    running = null;
  });
  return running;
}

/** Drop an entry the provider refused (after the learner has read why). */
export function discardWaiting(clientId: string): void {
  saveQueue(waiting().filter((q) => q.clientId !== clientId));
}

export const today = () => localDate(new Date());

// ── Mentor ─────────────────────────────────────────────────────────────────────────
export interface MentorWeek {
  id: string;
  week_start: string;
  status: 'open' | 'signed' | 'returned';
  comment: string | null;
  learner: string | null;
  entries: number;
  minutes: number;
}

export interface MentorLearner {
  placement_id: string;
  enrolment_id: string;
  name: string | null;
  site: string | null;
  active: boolean;
  modules: (ModuleHours & { signoff: { status: 'signed' | 'checked' | 'queried'; check_comment: string | null } | null })[];
}

export interface MentorWeeks {
  is_mentor: boolean;
  statement: string;
  learners: MentorLearner[];
  weeks: MentorWeek[];
}

export interface WeekDetail extends Omit<MentorWeek, 'entries' | 'minutes'> {
  entries: (LogbookEntry & { module: string | null; activity: string })[];
}

export const useMentorWeeks = (enabled = true) =>
  useQuery({ queryKey: ['mentoring-weeks'], queryFn: () => json<{ data: MentorWeeks }>(api.GET('/mentoring/weeks' as never)).then((r) => r.data), enabled });

export const useWeek = (id: string) =>
  useQuery({ queryKey: ['logbook-week', id], queryFn: () => json<{ data: WeekDetail }>(api.GET(`/logbook-weeks/${id}` as never)).then((r) => r.data) });

export const signWeek = (id: string) => json(api.POST(`/logbook-weeks/${id}/sign` as never, { body: { confirm: true } } as never));
export const returnWeek = (id: string, comment: string, entryIds: string[]) =>
  json(api.POST(`/logbook-weeks/${id}/return` as never, { body: { comment, entry_ids: entryIds } } as never));
export const signOffModule = (enrolmentId: string, moduleId: string, comment: string) =>
  json(api.POST('/logbook-signoffs' as never, { body: { enrolment_id: enrolmentId, module_id: moduleId, authentic: true, comment: comment || null } } as never));
