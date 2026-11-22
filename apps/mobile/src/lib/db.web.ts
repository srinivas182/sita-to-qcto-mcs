/** Web preview only: in-memory stand-ins for the encrypted SQLite database. */
import { MemoryOutboxStore } from '@/core/outbox';

const memory = new Map<string, string>();

export async function openDatabase(): Promise<void> {}

export async function deleteDatabase(): Promise<void> {
  memory.clear();
}

export const cache = {
  async get<T>(key: string): Promise<T | null> {
    const v = memory.get(key);
    return v ? (JSON.parse(v) as T) : null;
  },
  async set(key: string, value: unknown): Promise<void> {
    memory.set(key, JSON.stringify(value));
  },
};

export class SqliteOutboxStore extends MemoryOutboxStore {}
