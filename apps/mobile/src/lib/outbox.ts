import { Outbox, type OutboxItem, type SendResult } from '@/core/outbox';
import { API_BASE_URL } from './config';
import { SqliteOutboxStore } from './db';
import { randomId } from './random';
import { getTenantCode, session } from './session';

export const outbox = new Outbox(new SqliteOutboxStore(), randomId);

/** Sends one outbox item; the Idempotency-Key makes retries safe on the server. */
export async function sendOutboxItem(item: OutboxItem): Promise<SendResult> {
  const token = await session.accessToken().catch(() => null);
  if (!token) return { status: 0, body: 'not signed in' };

  const res = await fetch(`${API_BASE_URL}${item.path}`, {
    method: item.method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'Idempotency-Key': item.idempotencyKey,
      ...(getTenantCode() ? { 'X-Tenant': getTenantCode()! } : {}),
    },
    body: item.method === 'DELETE' ? undefined : JSON.stringify(item.body ?? {}),
  });

  return { status: res.status, body: await res.json().catch(() => null) };
}
