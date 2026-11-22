/**
 * Outbox: every change made in the app is written locally first, then sent when the
 * phone is online. Each item carries an idempotency key, so a retry after a dropped
 * connection can never create a duplicate on the server.
 *
 * Rules
 * - Retries use exponential back-off with jitter (5 s → 15 min cap).
 * - Network errors, 408, 429 and 5xx are retried; 409/422 are conflicts (the server
 *   wins and the item is parked for the person to review); other 4xx fail.
 * - Items are sent in creation order per stream so dependent changes stay ordered.
 */

export type OutboxStatus = 'pending' | 'sending' | 'conflict' | 'failed';

export interface OutboxItem {
  id: string;
  idempotencyKey: string;
  stream: string;
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: string;
  body: unknown;
  attempts: number;
  nextAttemptAt: number;
  status: OutboxStatus;
  lastError: string | null;
  createdAt: number;
}

export interface OutboxStore {
  insert(item: OutboxItem): Promise<void>;
  update(id: string, patch: Partial<OutboxItem>): Promise<void>;
  remove(id: string): Promise<void>;
  /** Items in creation order. */
  all(): Promise<OutboxItem[]>;
}

export interface SendResult {
  status: number;
  body?: unknown;
}

export type Sender = (item: OutboxItem) => Promise<SendResult>;

export interface ProcessReport {
  sent: number;
  retried: number;
  conflicts: number;
  failed: number;
}

export const BASE_DELAY_MS = 5_000;
export const MAX_DELAY_MS = 15 * 60_000;

export function backoff(attempts: number, random: () => number = Math.random): number {
  const exp = Math.min(MAX_DELAY_MS, BASE_DELAY_MS * 2 ** Math.max(0, attempts - 1));
  return Math.round(exp / 2 + random() * (exp / 2)); // "equal jitter"
}

export function classify(status: number): 'ok' | 'retry' | 'conflict' | 'fail' {
  if (status >= 200 && status < 300) return 'ok';
  if (status === 0 || status === 408 || status === 429 || status >= 500) return 'retry';
  if (status === 409 || status === 422) return 'conflict';
  return 'fail';
}

export class Outbox {
  private running: Promise<ProcessReport> | null = null;

  constructor(
    private readonly store: OutboxStore,
    private readonly ids: () => string,
    private readonly now: () => number = Date.now,
    private readonly random: () => number = Math.random,
  ) {}

  async enqueue(input: { stream?: string; method: OutboxItem['method']; path: string; body?: unknown }): Promise<OutboxItem> {
    const item: OutboxItem = {
      id: this.ids(),
      idempotencyKey: this.ids(),
      stream: input.stream ?? 'default',
      method: input.method,
      path: input.path,
      body: input.body ?? null,
      attempts: 0,
      nextAttemptAt: this.now(),
      status: 'pending',
      lastError: null,
      createdAt: this.now(),
    };
    await this.store.insert(item);
    return item;
  }

  async pending(): Promise<OutboxItem[]> {
    return (await this.store.all()).filter((i) => i.status === 'pending' || i.status === 'sending');
  }

  async conflicts(): Promise<OutboxItem[]> {
    return (await this.store.all()).filter((i) => i.status === 'conflict' || i.status === 'failed');
  }

  /** Send everything that is due. Concurrent calls share one run. */
  process(send: Sender): Promise<ProcessReport> {
    this.running ??= this.run(send).finally(() => {
      this.running = null;
    });
    return this.running;
  }

  /** Put a parked item back in the queue (after the person reviews it). */
  async retry(id: string): Promise<void> {
    await this.store.update(id, { status: 'pending', nextAttemptAt: this.now(), lastError: null });
  }

  async discard(id: string): Promise<void> {
    await this.store.remove(id);
  }

  private async run(send: Sender): Promise<ProcessReport> {
    const report: ProcessReport = { sent: 0, retried: 0, conflicts: 0, failed: 0 };
    const blocked = new Set<string>();
    const items = await this.store.all();

    for (const item of items) {
      if (item.status === 'conflict' || item.status === 'failed') {
        blocked.add(item.stream); // keep order: later items wait behind a parked one
        continue;
      }
      if (blocked.has(item.stream) || item.nextAttemptAt > this.now()) {
        blocked.add(item.stream);
        continue;
      }

      await this.store.update(item.id, { status: 'sending' });
      let result: SendResult;
      try {
        result = await send(item);
      } catch (e) {
        result = { status: 0, body: e instanceof Error ? e.message : String(e) };
      }

      const attempts = item.attempts + 1;
      const outcome = classify(result.status);
      const error = outcome === 'ok' ? null : describe(result);

      if (outcome === 'ok') {
        await this.store.remove(item.id);
        report.sent++;
      } else if (outcome === 'retry') {
        await this.store.update(item.id, { status: 'pending', attempts, nextAttemptAt: this.now() + backoff(attempts, this.random), lastError: error });
        blocked.add(item.stream);
        report.retried++;
      } else {
        await this.store.update(item.id, { status: outcome === 'conflict' ? 'conflict' : 'failed', attempts, lastError: error });
        blocked.add(item.stream);
        report[outcome === 'conflict' ? 'conflicts' : 'failed']++;
      }
    }

    return report;
  }
}

function describe(result: SendResult): string {
  const body = result.body as { error?: { message?: string } } | string | undefined;
  if (typeof body === 'string') return body;
  return body?.error?.message ?? `HTTP ${result.status}`;
}

/** In-memory store (tests, and a fallback if the database cannot open). */
export class MemoryOutboxStore implements OutboxStore {
  private items = new Map<string, OutboxItem>();

  async insert(item: OutboxItem): Promise<void> {
    this.items.set(item.id, { ...item });
  }

  async update(id: string, patch: Partial<OutboxItem>): Promise<void> {
    const current = this.items.get(id);
    if (current) this.items.set(id, { ...current, ...patch });
  }

  async remove(id: string): Promise<void> {
    this.items.delete(id);
  }

  async all(): Promise<OutboxItem[]> {
    return [...this.items.values()].sort((a, b) => a.createdAt - b.createdAt || a.id.localeCompare(b.id));
  }
}
