import * as SQLite from 'expo-sqlite';
import type { OutboxItem, OutboxStore } from '@/core/outbox';
import { randomHex } from './random';
import { secure } from './secure';

/**
 * Encrypted local database (SQLCipher, key in the Keychain/Keystore). Holds the outbox
 * and cached data for offline use. Deleted on sign-out and remote wipe.
 */
const NAME = 'skills.db';
let db: SQLite.SQLiteDatabase | null = null;

export async function openDatabase(): Promise<SQLite.SQLiteDatabase> {
  if (db) return db;

  let key = await secure.get('db_key');
  if (!key) {
    key = randomHex(32);
    await secure.set('db_key', key);
  }

  const handle = await SQLite.openDatabaseAsync(NAME);
  await handle.execAsync(`PRAGMA key = "x'${key}'";`);
  await handle.execAsync(`
    PRAGMA journal_mode = WAL;
    CREATE TABLE IF NOT EXISTS outbox (
      id TEXT PRIMARY KEY NOT NULL,
      idempotency_key TEXT NOT NULL,
      stream TEXT NOT NULL,
      method TEXT NOT NULL,
      path TEXT NOT NULL,
      body TEXT,
      attempts INTEGER NOT NULL DEFAULT 0,
      next_attempt_at INTEGER NOT NULL,
      status TEXT NOT NULL,
      last_error TEXT,
      created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS cache (
      key TEXT PRIMARY KEY NOT NULL,
      value TEXT NOT NULL,
      updated_at INTEGER NOT NULL
    );
  `);

  db = handle;
  return handle;
}

export async function deleteDatabase(): Promise<void> {
  if (db) {
    await db.closeAsync().catch(() => undefined);
    db = null;
  }
  await SQLite.deleteDatabaseAsync(NAME).catch(() => undefined);
}

/** Small offline cache (e.g. /me, branding) so screens render without a network. */
export const cache = {
  async get<T>(key: string): Promise<T | null> {
    const row = await (await openDatabase()).getFirstAsync<{ value: string }>('SELECT value FROM cache WHERE key = ?', key);
    return row ? (JSON.parse(row.value) as T) : null;
  },
  async set(key: string, value: unknown): Promise<void> {
    await (await openDatabase()).runAsync('INSERT OR REPLACE INTO cache (key, value, updated_at) VALUES (?, ?, ?)', key, JSON.stringify(value), Date.now());
  },
};

type Row = {
  id: string;
  idempotency_key: string;
  stream: string;
  method: OutboxItem['method'];
  path: string;
  body: string | null;
  attempts: number;
  next_attempt_at: number;
  status: OutboxItem['status'];
  last_error: string | null;
  created_at: number;
};

const toItem = (r: Row): OutboxItem => ({
  id: r.id,
  idempotencyKey: r.idempotency_key,
  stream: r.stream,
  method: r.method,
  path: r.path,
  body: r.body ? JSON.parse(r.body) : null,
  attempts: r.attempts,
  nextAttemptAt: r.next_attempt_at,
  status: r.status,
  lastError: r.last_error,
  createdAt: r.created_at,
});

const columns: Record<keyof OutboxItem, string> = {
  id: 'id',
  idempotencyKey: 'idempotency_key',
  stream: 'stream',
  method: 'method',
  path: 'path',
  body: 'body',
  attempts: 'attempts',
  nextAttemptAt: 'next_attempt_at',
  status: 'status',
  lastError: 'last_error',
  createdAt: 'created_at',
};

export class SqliteOutboxStore implements OutboxStore {
  async insert(i: OutboxItem): Promise<void> {
    await (await openDatabase()).runAsync(
      'INSERT INTO outbox (id, idempotency_key, stream, method, path, body, attempts, next_attempt_at, status, last_error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      i.id, i.idempotencyKey, i.stream, i.method, i.path, JSON.stringify(i.body), i.attempts, i.nextAttemptAt, i.status, i.lastError, i.createdAt,
    );
  }

  async update(id: string, patch: Partial<OutboxItem>): Promise<void> {
    const entries = Object.entries(patch) as Array<[keyof OutboxItem, unknown]>;
    if (!entries.length) return;
    const sets = entries.map(([k]) => `${columns[k]} = ?`).join(', ');
    const values = entries.map(([k, v]) => (k === 'body' ? JSON.stringify(v) : (v as SQLite.SQLiteBindValue)));
    await (await openDatabase()).runAsync(`UPDATE outbox SET ${sets} WHERE id = ?`, ...values, id);
  }

  async remove(id: string): Promise<void> {
    await (await openDatabase()).runAsync('DELETE FROM outbox WHERE id = ?', id);
  }

  async all(): Promise<OutboxItem[]> {
    const rows = await (await openDatabase()).getAllAsync<Row>('SELECT * FROM outbox ORDER BY created_at, id');
    return rows.map(toItem);
  }
}
