import { useQuery } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { localDate } from '@/core/entryHash';
import { processQueue, type QueuedEntry, type QueuedPhoto } from '@/core/logbookQueue';
import { api, json } from './api';
import { API_BASE_URL } from './config';
import { uploadPhoto } from './enrolment';
import { randomId } from './random';
import { getTenantCode, session } from './session';

export type Method = 'test' | 'observation' | 'project' | 'assignment' | 'logbook';

export interface Instrument {
  id: string;
  module_id: string;
  method: Method;
  title: string;
  criteria: { key: string; text: string }[];
  pass_mark: number | null;
  instructions: string | null;
}

export interface Latest {
  id: string;
  attempt: number;
  outcome: 'competent' | 'nyc';
  score: number | null;
  feedback: string | null;
  remediation: string | null;
  assessed_on: string;
  decided_at: string;
  status: string;
  can_appeal?: boolean;
  appeal_until?: string;
  appeal?: { status: 'open' | 'upheld' | 'overturned'; decide_by: string; decision_note: string | null } | null;
}

export type ModuleState = 'competent' | 'nyc' | 'in_progress' | 'not_assessed' | 'no_assessment';

export interface ModuleResults {
  module_id: string;
  code: string;
  title: string;
  kind: 'KM' | 'PM' | 'WM';
  state: ModuleState;
  instruments: { instrument_id: string; title: string; method: Method; attempts: number; latest: Latest | null }[];
}

export interface LearnerDetail {
  enrolment_id: string;
  name: string | null;
  modules: ModuleResults[];
  instruments: Instrument[];
  max_attempts: number;
}

export interface Statement {
  id: string;
  number: string;
  status: 'issued' | 'withdrawn';
  issued_at: string;
}

// ── Offline copies (so an assessor can observe on site without signal) and the waiting decisions ──
const dir = () => new Directory(Paths.document, 'assess');
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

const cacheName = (key: string) => `cache-${key.replace(/[^a-z0-9]/gi, '_')}.json`;

/** A GET that falls back to the copy last seen when offline. */
function offlineQuery<T>(key: string, path: string, online: boolean, enabled = true) {
  return useQuery({
    queryKey: ['assess', key, online],
    enabled,
    queryFn: async () => {
      if (!online) {
        const c = readJson<T | null>(cacheName(key), null);
        if (c) return c;
      }
      const data = await json<{ data: T }>(api.GET(path as never)).then((r) => r.data);
      writeJson(cacheName(key), data);
      return data;
    },
  });
}

export const useAssessing = (online: boolean) =>
  offlineQuery<{ id: string; name: string; learners: number }[]>('assessing', '/me/assessing', online);

export const useCohortGrid = (cohortId: string, online: boolean) =>
  offlineQuery<{ modules: { id: string; code: string }[]; learners: { enrolment_id: string; name: string | null; modules: { module_id: string; state: ModuleState }[] }[] }>(
    `grid-${cohortId}`, `/cohorts/${cohortId}/results`, online);

export const useLearnerDetail = (cohortId: string, enrolmentId: string, online: boolean) =>
  offlineQuery<LearnerDetail>(`learner-${enrolmentId}`, `/cohorts/${cohortId}/results/${enrolmentId}`, online);

export const waitingDecisions = (): QueuedEntry[] => readJson<QueuedEntry[]>('queue.json', []);
const saveQueue = (q: QueuedEntry[]) => writeJson('queue.json', q);

function hex(buf: ArrayBuffer): string {
  return Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join('');
}

async function keep(uri: string, id: string, n: number): Promise<QueuedPhoto> {
  const d = dir();
  if (!d.exists) d.create({ intermediates: true });
  const target = new File(d, `${id}-${n}.jpg`);
  if (target.exists) target.delete();
  new File(uri).copy(target);
  return { uri: target.uri, hash: hex(await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, await target.bytes())) };
}

export interface Decision {
  enrolmentId: string;
  instrumentId: string;
  learner: string;
  title: string;
  outcome: 'competent' | 'nyc';
  score: number | null;
  criteria: { key: string; met: boolean; comment: string | null }[];
  feedback: string;
  remediation: string;
  photos: string[];
}

/** Keep the decision on the phone (with its photos) until it can be sent. */
export async function recordDecision(d: Decision): Promise<QueuedEntry> {
  const clientId = randomId();
  const photos: QueuedPhoto[] = [];
  for (const [n, uri] of d.photos.entries()) photos.push(await keep(uri, clientId, n));
  const item: QueuedEntry = {
    clientId,
    path: '/assessment-results',
    body: {
      client_id: clientId, enrolment_id: d.enrolmentId, instrument_id: d.instrumentId, outcome: d.outcome, score: d.score, criteria: d.criteria,
      feedback: d.feedback.trim() || null, remediation: d.remediation.trim() || null, assessed_on: localDate(new Date()), learner: d.learner, title: d.title,
    },
    photos,
    createdAt: Date.now(),
    status: 'pending',
    error: null,
  };
  saveQueue([...waitingDecisions(), item]);
  return item;
}

async function post(path: string, body: Record<string, unknown>): Promise<{ status: number; body?: unknown }> {
  const token = await session.accessToken().catch(() => null);
  if (!token) return { status: 0 };
  const { learner: _l, title: _t, ...send } = body;
  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}`, ...(getTenantCode() ? { 'X-Tenant': getTenantCode()! } : {}) },
    body: JSON.stringify(send),
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

let running: Promise<number> | null = null;

/** Send waiting decisions, evidence first. Returns how many are still waiting. */
export function syncDecisions(): Promise<number> {
  running ??= (async () => {
    const queue = waitingDecisions();
    if (queue.every((q) => q.status === 'conflict')) return queue.length;
    const r = await processQueue(queue, { upload: (p) => uploadPhoto('assessment_evidence', p.uri, p.uri.split('/').pop() ?? 'evidence.jpg'), post });
    for (const q of queue) {
      if (r.queue.some((x) => x.clientId === q.clientId)) continue;
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

export function discardDecision(clientId: string): void {
  saveQueue(waitingDecisions().filter((q) => q.clientId !== clientId));
}

// ── Learner ────────────────────────────────────────────────────────────────────────────
export const useMyResults = () =>
  useQuery({ queryKey: ['my-results'], queryFn: () => json<{ data: { enrolment_id: string; cohort: string | null; modules: ModuleResults[] }[] }>(api.GET('/me/results' as never)).then((r) => r.data) });

export const useMyStatements = () =>
  useQuery({ queryKey: ['my-sors'], queryFn: () => json<{ data: Statement[] }>(api.GET('/me/statements-of-results' as never)).then((r) => r.data) });

export interface MyEisa {
  data: { id: string; eisa_on: string; assessment_centre: string | null; assessment_centre_name: string | null; declared_at: string | null; status: 'draft' | 'registered' | 'withdrawn' }[];
  declaration: string;
  name: string;
}

export const useMyEisa = () => useQuery({ queryKey: ['my-eisa'], queryFn: () => json<MyEisa>(api.GET('/me/eisa' as never)) });

/** The learner signs the EISA declaration: tick and type their full name. */
export const declareEisa = (id: string, name: string) => json(api.POST(`/me/eisa/${id}/declare` as never, { body: { confirm: true, name } } as never));

export const appeal = (resultId: string, reason: string) => json(api.POST(`/assessment-results/${resultId}/appeal` as never, { body: { reason } } as never));

/** Download the Statement of Results PDF and open the share sheet. */
export async function shareStatement(s: Statement): Promise<void> {
  const token = await session.accessToken().catch(() => null);
  const tenant = getTenantCode();
  const target = new File(Paths.cache, `${s.number}.pdf`);
  if (target.exists) target.delete();
  await File.downloadFileAsync(`${API_BASE_URL}/statements-of-results/${s.id}/pdf`, target, {
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(tenant ? { 'X-Tenant': tenant } : {}) },
  });
  await Sharing.shareAsync(target.uri, { mimeType: 'application/pdf', dialogTitle: s.number });
}
