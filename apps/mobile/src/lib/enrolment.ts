import { useQuery } from '@tanstack/react-query';
import { api, json } from './api';
import { getTenantCode, session } from './session';

export interface Question {
  key: string;
  label: string;
  type: 'yesno' | 'text' | 'choice';
  required?: boolean;
  options?: string[];
}

export interface OpenIntake {
  id: string;
  slug: string;
  title: string;
  description: string | null;
  provider: string;
  accepting: boolean;
  closes_on: string;
  starts_on: string;
  ends_on: string;
  mode: string;
  questions: Question[] | null;
  document_types: string[] | null;
  qualification: { title: string; saqa_id: string | null; nqf_level: number | null } | null;
  centre: { name: string; city: string | null } | null;
}

export interface MyApplication {
  id: string;
  reference: string;
  status: string;
  reason: string | null;
  waitlist_position: number | null;
  intake: string | null;
  intake_id: string;
  submitted_at: string;
}

export interface Requirement {
  type: string;
  label: string;
  state: 'missing' | 'pending' | 'accepted' | 'rejected' | 'expired';
  document_id: string | null;
  problem: string | null;
}

export interface MyDocuments {
  documents: { id: string; type: string; label: string; status: string; reason: string | null; checks: Record<string, unknown> | null }[];
  needed: { application_id: string; reference: string; intake: string | null; complete: boolean; items: Requirement[] }[];
  types: { type: string; label: string; certified: boolean; max_age_days: number | null }[];
}

export interface Offer {
  id: string;
  status: 'open' | 'accepted' | 'declined' | 'lapsed' | 'withdrawn';
  expires_at: string;
  reference: string;
  provider: string;
  learner: string;
  course: { title: string; cohort: string; starts_on: string; ends_on: string };
  needs_guardian: boolean;
  guardian: { name: string; phone: string; relationship: string } | null;
  consent: { version: number; text: string };
  agreement_id: string | null;
  sign_signer_id: string | null;
}

export interface Course {
  enrolment_id: string;
  status: string;
  cohort: string | null;
  starts_on: string | null;
  ends_on: string | null;
  qualification: { id: string; title: string; saqa_id: string | null; nqf_level: number | null } | null;
}

const get = <T,>(path: string) => json<{ data: T }>(api.GET(path as never)).then((r) => r.data);

export const useOpenIntakes = (enabled = true) => useQuery({ queryKey: ['intakes', 'open'], queryFn: () => get<OpenIntake[]>('/public/intakes'), enabled });
export const useMyApplications = (enabled = true) => useQuery({ queryKey: ['my-applications'], queryFn: () => get<MyApplication[]>('/me/applications'), enabled });
export const useMyDocuments = (enabled = true) => useQuery({ queryKey: ['my-documents'], queryFn: () => get<MyDocuments>('/me/documents'), enabled });
export const useMyOffers = (enabled = true) => useQuery({ queryKey: ['my-offers'], queryFn: () => get<Offer[]>('/me/offers'), enabled });
export const useMyCourses = (enabled = true) => useQuery({ queryKey: ['my-courses'], queryFn: () => get<Course[]>('/me/courses'), enabled });

export const APPLICATION_STATE: Record<string, string> = {
  submitted: 'Received',
  shortlisted: 'Shortlisted',
  waitlisted: 'On the waiting list',
  offered: 'Offered a place',
  accepted: 'Accepted: sign your agreement',
  enrolled: 'Enrolled',
  declined: 'Not successful',
  withdrawn: 'Withdrawn',
};

interface UploadTarget {
  method: 'PUT';
  url: string;
  headers: Record<string, string>;
  via?: 'api';
}

/**
 * Upload a photo for a purpose: ask the API where to send it, send it (straight to cloud
 * storage when available), then wait while it is checked. Returns the file id.
 */
export async function uploadPhoto(purpose: string, uri: string, name: string): Promise<string> {
  const blob = await (await fetch(uri)).blob();
  const created = await json<{ data: { id: string }; upload: UploadTarget }>(
    api.POST('/files' as never, { body: { purpose, name, mime: blob.type || 'image/jpeg', size: blob.size } } as never),
  );
  const headers: Record<string, string> = { ...created.upload.headers };
  if (created.upload.via === 'api') {
    const token = await session.accessToken().catch(() => null);
    if (token) headers.Authorization = `Bearer ${token}`;
    const tenant = getTenantCode();
    if (tenant) headers['X-Tenant'] = tenant;
    headers.Accept = 'application/json';
  }
  const res = await fetch(created.upload.url, { method: 'PUT', headers, body: blob });
  if (!res.ok) throw new Error('The upload failed. Check your connection and try again.');
  let current = await json<{ data: { status: string; status_reason: string | null } }>(api.POST(`/files/${created.data.id}/complete` as never, {} as never));
  for (let i = 0; current.data.status === 'processing' && i < 40; i++) {
    await new Promise((r) => setTimeout(r, i < 10 ? 300 : 1500));
    current = await json(api.GET(`/files/${created.data.id}` as never));
  }
  if (current.data.status !== 'ready') throw new Error(current.data.status_reason ?? 'The photo could not be checked. Please try again.');
  return created.data.id;
}
