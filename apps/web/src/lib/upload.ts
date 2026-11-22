import { call } from './api';

export interface UploadedFile {
  id: string;
  purpose: string;
  name: string;
  mime: string;
  size: number;
  status: 'pending' | 'processing' | 'ready' | 'rejected' | 'failed';
  status_reason: string | null;
  variants: Record<string, { width: number; height: number }>;
}

interface Target {
  method: 'PUT';
  url: string;
  headers: Record<string, string>;
  via?: 'api';
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]!) : null;
}

/** PUT with progress. XHR, because fetch has no upload progress events. */
function put(target: Target, file: File, onProgress?: (fraction: number) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(target.method, target.url);
    for (const [k, v] of Object.entries(target.headers)) xhr.setRequestHeader(k, v);
    if (target.via === 'api') {
      // Through our own API: send the session cookie and the CSRF token.
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      const xsrf = readCookie('XSRF-TOKEN');
      if (xsrf) xhr.setRequestHeader('X-XSRF-TOKEN', xsrf);
    }
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress?.(e.loaded / e.total);
    xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error(uploadError(xhr))));
    xhr.onerror = () => reject(new Error('The upload was interrupted. Check your connection and try again.'));
    xhr.send(file);
  });
}

function uploadError(xhr: XMLHttpRequest): string {
  try {
    const body = JSON.parse(xhr.responseText) as { error?: { message?: string } };
    if (body.error?.message) return body.error.message;
  } catch {
    // Cloud stores answer in XML.
  }
  return 'The upload failed. Please try again.';
}

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Upload a file for a purpose: ask the API where to send it, send it (straight to
 * cloud storage when available), then wait while it is checked for viruses.
 */
export async function uploadFile(
  purpose: string,
  file: File,
  onProgress?: (fraction: number) => void,
): Promise<UploadedFile> {
  const created = await call<{ data: UploadedFile; upload: Target }>('POST', '/files', {
    purpose,
    name: file.name,
    mime: file.type || 'application/octet-stream',
    size: file.size,
  });

  await put(created.upload, file, onProgress);
  let current = (await call<{ data: UploadedFile }>('POST', `/files/${created.data.id}/complete`)).data;

  // Scanning usually takes under a second; give it up to a minute.
  for (let i = 0; current.status === 'processing' && i < 40; i++) {
    await wait(i < 10 ? 300 : 1500);
    current = (await call<{ data: UploadedFile }>('GET', `/files/${created.data.id}`)).data;
  }

  if (current.status !== 'ready') {
    throw new Error(current.status_reason ?? 'The file could not be checked. Please try again.');
  }

  return current;
}
