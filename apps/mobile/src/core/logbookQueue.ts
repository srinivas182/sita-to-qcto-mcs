/**
 * Logbook entries written on site wait on the phone (with their photos) until there is a
 * connection. They are sent in the order written: photos are uploaded first, then the entry.
 * The server takes the same client id twice as one entry, so a retry can't duplicate it.
 */
export interface QueuedPhoto {
  uri: string;
  hash: string;
  fileId?: string;
}

export interface QueuedEntry {
  clientId: string;
  path: string; // /me/logbook, or /me/logbook/{id}/correct
  body: Record<string, unknown>; // the entry without file_ids
  photos: QueuedPhoto[];
  createdAt: number;
  status: 'pending' | 'conflict';
  error: string | null;
}

export interface QueueDeps {
  upload: (photo: QueuedPhoto) => Promise<string>; // returns the file id
  post: (path: string, body: Record<string, unknown>) => Promise<{ status: number; body?: unknown }>;
}

export interface QueueReport {
  queue: QueuedEntry[];
  sent: number;
  conflicts: number;
  stoppedOnNetwork: boolean;
}

const retryable = (s: number) => s === 0 || s === 402 || s === 408 || s === 429 || s >= 500;

function message(body: unknown): string {
  const b = body as { message?: string; errors?: Record<string, string[]> } | null;
  const first = b?.errors ? Object.values(b.errors)[0]?.[0] : undefined;
  return first ?? b?.message ?? 'The provider could not take this entry.';
}

/** Send what is waiting, oldest first. Stops at the first network failure to keep the order. */
export async function processQueue(queue: QueuedEntry[], deps: QueueDeps): Promise<QueueReport> {
  const out = queue.map((q) => ({ ...q, photos: q.photos.map((p) => ({ ...p })) }));
  let sent = 0;
  let conflicts = 0;
  for (const item of [...out].sort((a, b) => a.createdAt - b.createdAt)) {
    if (item.status === 'conflict') continue;
    try {
      for (const p of item.photos) {
        p.fileId ??= await deps.upload(p);
      }
    } catch {
      return { queue: out, sent, conflicts, stoppedOnNetwork: true };
    }
    let r: { status: number; body?: unknown };
    try {
      r = await deps.post(item.path, { ...item.body, file_ids: item.photos.map((p) => p.fileId) });
    } catch {
      r = { status: 0 };
    }
    if (r.status >= 200 && r.status < 300) {
      out.splice(out.indexOf(item), 1);
      sent++;
    } else if (retryable(r.status)) {
      return { queue: out, sent, conflicts, stoppedOnNetwork: true };
    } else {
      item.status = 'conflict';
      item.error = message(r.body);
      conflicts++;
    }
  }
  return { queue: out, sent, conflicts, stoppedOnNetwork: false };
}

/** The hash the next entry should chain onto: the newest waiting entry, else the server's last. */
export function chainHead(queue: QueuedEntry[], serverLast: string | null): string | null {
  const waiting = queue.filter((q) => q.status === 'pending').sort((a, b) => b.createdAt - a.createdAt)[0];
  return (waiting?.body.hash as string | undefined) ?? serverLast;
}
