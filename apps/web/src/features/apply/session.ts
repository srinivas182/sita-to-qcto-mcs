import { call } from '@/lib/api';
import { put, type Target } from '@/lib/upload';
import type { ApplicationData } from './types';

const KEY = 'skills.application';

export interface Saved {
  id: string;
  token: string;
}

/** The resume token lives in this browser only; lost tokens are recovered by email code. */
export function loadSaved(): Saved | null {
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as Saved) : null;
  } catch {
    return null;
  }
}

export function save(saved: Saved | null): void {
  try {
    if (saved) localStorage.setItem(KEY, JSON.stringify(saved));
    else localStorage.removeItem(KEY);
  } catch {
    // Private mode: the application still works for this visit.
  }
}

/** Calls made as the applicant (the token header, never a session). */
export function applicant(saved: Saved) {
  const headers = { 'X-Application-Token': saved.token };
  const path = (p = '') => `/applications/${saved.id}${p}`;
  const data = async (p: Promise<{ data: ApplicationData }>) => (await p).data;

  return {
    get: () => data(call('GET', path(), undefined, undefined, headers)),
    update: (body: Partial<ApplicationData>) => data(call('PATCH', path(), body, undefined, headers)),
    verify: (code: string) => data(call('POST', path('/verify-email'), { code }, undefined, headers)),
    resend: () => call('POST', path('/email-code'), {}, undefined, headers),
    consent: () => data(call('POST', path('/consent'), { accept: true }, undefined, headers)),
    submit: () => data(call('POST', path('/submit'), {}, undefined, headers)),
    detach: (file: string) => data(call('DELETE', path(`/files/${file}`), undefined, undefined, headers)),
    upload: async (kind: string, file: File, onProgress: (f: number) => void) => {
      const created = await call<{ file: { id: string }; upload: Target }>(
        'POST',
        path('/files'),
        { kind, name: file.name, mime: file.type || 'application/octet-stream', size: file.size },
        undefined,
        headers,
      );
      await put(created.upload, file, onProgress, created.upload.via === 'api' ? headers : {});
      return data(call('POST', path(`/files/${created.file.id}/complete`), {}, undefined, headers));
    },
  };
}
