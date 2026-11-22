import { verify } from 'node:crypto';
import { createPublicKey } from 'node:crypto';
import { randomBytes } from 'node:crypto';
import { describe, expect, it, vi } from 'vitest';
import { generateDeviceKey, sha256Hex, signMessage, toBase64 } from '../src/core/deviceKey';
import { hashPin, isValidPin, verifyPin } from '../src/core/lock';
import { Session, SessionExpired, refreshMessage, type RefreshRequest, type TokenPair } from '../src/core/session';

const iso = (ms: number) => new Date(ms).toISOString();

function pair(now: number, over: Partial<TokenPair> = {}): TokenPair {
  return {
    access_token: 'a1',
    expires_at: iso(now + 15 * 60_000),
    refresh_token: 'r1',
    refresh_expires_at: iso(now + 30 * 86_400_000),
    device_id: '01DEVICE0000000000000000000',
    offline_until: iso(now + 14 * 86_400_000),
    ...over,
  };
}

function memoryStorage(initial: TokenPair | null) {
  let value = initial;
  return { load: async () => value, save: async (p: TokenPair) => void (value = p), clear: async () => void (value = null), peek: () => value };
}

describe('device key', () => {
  it('signs refresh messages that standard Ed25519 (libsodium on the server) verifies', async () => {
    const key = generateDeviceKey((n) => new Uint8Array(randomBytes(n)));
    const message = await refreshMessage('01DEV', 1_764_000_000, 'r.token', async (s) => sha256Hex(s));
    const signature = signMessage(key.secretKey, message);

    // Wrap the raw 32-byte public key as SPKI for Node's verifier.
    const spki = Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(key.publicKey)]);
    const publicKey = createPublicKey({ key: spki, format: 'der', type: 'spki' });

    expect(verify(null, Buffer.from(message), publicKey, Buffer.from(signature))).toBe(true);
    expect(message).toBe(`refresh.01DEV.1764000000.${sha256Hex('r.token')}`);
    expect(toBase64(key.publicKey)).toHaveLength(44);
  });
});

describe('session', () => {
  const sha = async (s: string) => sha256Hex(s);

  it('uses the access token until a minute before expiry, then refreshes once for concurrent callers', async () => {
    let now = Date.parse('2026-11-22T10:00:00Z');
    const storage = memoryStorage(pair(now));
    const refreshCall = vi.fn(async (_req: RefreshRequest) => ({ status: 200, body: pair(now, { access_token: 'a2', refresh_token: 'r2' }) }));
    const session = new Session(storage, refreshCall, async () => 'sig', sha, () => undefined, () => now);

    expect(await session.accessToken()).toBe('a1');
    now += 14.5 * 60_000;
    const tokens = await Promise.all([session.accessToken(), session.accessToken(), session.accessToken()]);

    expect(tokens).toEqual(['a2', 'a2', 'a2']);
    expect(refreshCall).toHaveBeenCalledTimes(1);
    expect(refreshCall.mock.calls[0]![0]).toMatchObject({ refresh_token: 'r1', signature: 'sig', timestamp: Math.floor(now / 1000) });
    expect(storage.peek()?.refresh_token).toBe('r2');
  });

  it('ends the session and wipes when the device was revoked', async () => {
    const now = Date.now();
    const storage = memoryStorage(pair(now, { expires_at: iso(now - 1) }));
    const ended = vi.fn();
    const session = new Session(storage, async () => ({ status: 401, body: { error: { code: 'device_revoked' } } }), async () => 's', sha, ended);

    await expect(session.accessToken()).rejects.toBeInstanceOf(SessionExpired);
    expect(ended).toHaveBeenCalledWith('device_revoked');
    expect(storage.peek()).toBeNull();
  });

  it('keeps the session through network trouble so the app works offline', async () => {
    const now = Date.now();
    const storage = memoryStorage(pair(now, { expires_at: iso(now - 1) }));
    const ended = vi.fn();
    const session = new Session(storage, async () => ({ status: 0, body: null }), async () => 's', sha, ended);

    await expect(session.accessToken()).rejects.toThrow('refresh failed');
    expect(ended).not.toHaveBeenCalled();
    expect(await session.canOpenOffline()).toBe(true);
  });

  it('allows offline opening for 14 days only', async () => {
    let now = Date.now();
    const session = new Session(memoryStorage(pair(now)), async () => ({ status: 0, body: null }), async () => 's', sha, () => undefined, () => now);

    expect(await session.canOpenOffline()).toBe(true);
    now += 15 * 86_400_000;
    expect(await session.canOpenOffline()).toBe(false);
  });
});

describe('app PIN', () => {
  it('stores only a salted hash and verifies', () => {
    const hash = hashPin('482915', 'salt-1', 1000);
    expect(hash).not.toContain('482915');
    expect(verifyPin('482915', 'salt-1', hash, 1000)).toBe(true);
    expect(verifyPin('482916', 'salt-1', hash, 1000)).toBe(false);
    expect(hashPin('482915', 'salt-2', 1000)).not.toBe(hash);
  });

  it('rejects weak PINs', () => {
    expect(['111111', '123456', '654321', '345678', '12345', 'abcdef'].map(isValidPin)).toEqual([false, false, false, false, false, false]);
    expect(isValidPin('482915')).toBe(true);
  });
});
