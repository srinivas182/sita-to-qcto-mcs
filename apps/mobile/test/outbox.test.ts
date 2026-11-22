import { describe, expect, it } from 'vitest';
import { backoff, classify, MemoryOutboxStore, Outbox, type OutboxItem, type SendResult } from '../src/core/outbox';

function setup() {
  let t = 1_000_000;
  let n = 0;
  const clock = { now: () => t, advance: (ms: number) => (t += ms) };
  const store = new MemoryOutboxStore();
  const outbox = new Outbox(store, () => `id-${++n}`.padEnd(6, '0'), clock.now, () => 0.5);
  return { clock, store, outbox };
}

describe('outbox', () => {
  it('sends queued items in order with idempotency keys and clears them', async () => {
    const { outbox } = setup();
    await outbox.enqueue({ method: 'POST', path: '/logbook', body: { hours: 4 } });
    await outbox.enqueue({ method: 'POST', path: '/logbook', body: { hours: 3 } });
    const seen: OutboxItem[] = [];

    const report = await outbox.process(async (item) => {
      seen.push(item);
      return { status: 201 };
    });

    expect(report).toEqual({ sent: 2, retried: 0, conflicts: 0, failed: 0 });
    expect(seen.map((i) => (i.body as { hours: number }).hours)).toEqual([4, 3]);
    expect(new Set(seen.map((i) => i.idempotencyKey)).size).toBe(2);
    expect(await outbox.pending()).toEqual([]);
  });

  it('retries network and server errors with back-off, keeping the same idempotency key', async () => {
    const { outbox, clock } = setup();
    const item = await outbox.enqueue({ method: 'POST', path: '/x' });
    const results: SendResult[] = [{ status: 0 }, { status: 503 }, { status: 200 }];
    const keys: string[] = [];
    const send = async (i: OutboxItem) => {
      keys.push(i.idempotencyKey);
      return results.shift()!;
    };

    expect((await outbox.process(send)).retried).toBe(1);
    expect((await outbox.process(send)).retried).toBe(0); // not due yet
    clock.advance(backoff(1, () => 0.5));
    expect((await outbox.process(send)).retried).toBe(1);
    clock.advance(backoff(2, () => 0.5));
    expect((await outbox.process(send)).sent).toBe(1);

    expect(keys).toEqual([item.idempotencyKey, item.idempotencyKey, item.idempotencyKey]);
  });

  it('parks conflicts and holds later items of the same stream', async () => {
    const { outbox } = setup();
    await outbox.enqueue({ stream: 'learner-1', method: 'PUT', path: '/a' });
    await outbox.enqueue({ stream: 'learner-1', method: 'PUT', path: '/b' });
    await outbox.enqueue({ stream: 'learner-2', method: 'PUT', path: '/c' });

    const report = await outbox.process(async (i) => (i.path === '/a' ? { status: 409, body: { error: { message: 'Changed on the server' } } } : { status: 200 }));

    expect(report).toEqual({ sent: 1, retried: 0, conflicts: 1, failed: 0 });
    const parked = await outbox.conflicts();
    expect(parked[0]).toMatchObject({ path: '/a', status: 'conflict', lastError: 'Changed on the server' });
    expect((await outbox.pending()).map((i) => i.path)).toEqual(['/b']);

    await outbox.discard(parked[0]!.id);
    expect((await outbox.process(async () => ({ status: 200 }))).sent).toBe(1);
  });

  it('shares one run between concurrent triggers', async () => {
    const { outbox } = setup();
    await outbox.enqueue({ method: 'POST', path: '/x' });
    let calls = 0;
    const send = async () => {
      calls++;
      await new Promise((r) => setTimeout(r, 5));
      return { status: 200 };
    };

    await Promise.all([outbox.process(send), outbox.process(send), outbox.process(send)]);
    expect(calls).toBe(1);
  });

  it('classifies responses and caps back-off', () => {
    expect([200, 0, 408, 429, 500, 409, 422, 403, 402].map(classify)).toEqual(['ok', 'retry', 'retry', 'retry', 'retry', 'conflict', 'conflict', 'fail', 'retry']);
    expect(backoff(1, () => 0)).toBe(2_500);
    expect(backoff(50, () => 1)).toBe(15 * 60_000);
  });
});
