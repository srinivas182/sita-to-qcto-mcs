/**
 * Web preview only (design reviews in a browser). Phones use the Keychain/Keystore
 * implementation in secure.ts; this file is never part of an iOS/Android build.
 */
export type SecureKey =
  | 'tenant'
  | 'session'
  | 'device_secret'
  | 'db_key'
  | 'pin_hash'
  | 'pin_salt'
  | 'pin_attempts'
  | 'lock_method'
  | 'locale'
  | 'theme';

const ns = (k: string) => `skills-preview:${k}`;

export const secure = {
  get: async (key: SecureKey) => localStorage.getItem(ns(key)),
  set: async (key: SecureKey, value: string) => localStorage.setItem(ns(key), value),
  remove: async (key: SecureKey) => localStorage.removeItem(ns(key)),
  async getJson<T>(key: SecureKey): Promise<T | null> {
    const raw = localStorage.getItem(ns(key));
    return raw ? (JSON.parse(raw) as T) : null;
  },
  setJson: async (key: SecureKey, value: unknown) => localStorage.setItem(ns(key), JSON.stringify(value)),
};

export const PERSONAL_KEYS: SecureKey[] = ['tenant', 'session', 'device_secret', 'db_key', 'pin_hash', 'pin_salt', 'pin_attempts', 'lock_method'];
