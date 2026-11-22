import { generateDeviceKey, publicKeyFor, sha256Hex, signMessage, toBase64, fromBase64 } from '@/core/deviceKey';
import { Session, type SessionExpired, type TokenPair } from '@/core/session';
import { API_BASE_URL } from './config';
import { randomBytes } from './random';
import { secure } from './secure';

let tenantCode: string | null = null;
let onEnded: (reason: SessionExpired['reason']) => void = () => undefined;

export function setTenantCode(code: string | null) {
  tenantCode = code;
}

export function getTenantCode(): string | null {
  return tenantCode;
}

export function onSessionEnded(handler: (reason: SessionExpired['reason']) => void) {
  onEnded = handler;
}

/** The device key, created once per install and kept in the Keychain / Keystore. */
async function deviceSecret(): Promise<Uint8Array> {
  const stored = await secure.get('device_secret');
  if (stored) return fromBase64(stored);
  const key = generateDeviceKey(randomBytes);
  await secure.set('device_secret', toBase64(key.secretKey));
  return key.secretKey;
}

export async function devicePublicKey(): Promise<string> {
  return toBase64(publicKeyFor(await deviceSecret()));
}

export const session = new Session(
  {
    load: () => secure.getJson<TokenPair>('session'),
    save: (pair) => secure.setJson('session', pair),
    clear: () => secure.remove('session'),
  },
  async (body) => {
    try {
      const res = await fetch(`${API_BASE_URL}/auth/token/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(tenantCode ? { 'X-Tenant': tenantCode } : {}) },
        body: JSON.stringify(body),
      });
      return { status: res.status, body: await res.json().catch(() => null) };
    } catch {
      return { status: 0, body: null };
    }
  },
  async (message) => toBase64(signMessage(await deviceSecret(), message)),
  async (value) => sha256Hex(value),
  (reason) => onEnded(reason),
);
