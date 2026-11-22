import * as SecureStore from 'expo-secure-store';

/**
 * Keychain (iOS) / Keystore-backed storage (Android). Readable only while the phone is
 * unlocked, never synced to other devices or backups.
 */
const options: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

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

export const secure = {
  get: (key: SecureKey) => SecureStore.getItemAsync(key, options),
  set: (key: SecureKey, value: string) => SecureStore.setItemAsync(key, value, options),
  remove: (key: SecureKey) => SecureStore.deleteItemAsync(key, options),
  async getJson<T>(key: SecureKey): Promise<T | null> {
    const raw = await SecureStore.getItemAsync(key, options);
    return raw ? (JSON.parse(raw) as T) : null;
  },
  setJson: (key: SecureKey, value: unknown) => SecureStore.setItemAsync(key, JSON.stringify(value), options),
};

/** Everything tied to the person and their provider (kept: language and theme). */
export const PERSONAL_KEYS: SecureKey[] = ['tenant', 'session', 'device_secret', 'db_key', 'pin_hash', 'pin_salt', 'pin_attempts', 'lock_method'];
