/**
 * Device session: short-lived access token + rotating refresh token, both bound to
 * this phone. Refresh needs an Ed25519 signature from the key in the Keychain /
 * Keystore (see API docs/architecture/identity-access.md).
 */

export interface TokenPair {
  access_token: string;
  expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  device_id: string;
  offline_until: string;
}

export interface SessionStorage {
  load(): Promise<TokenPair | null>;
  save(pair: TokenPair): Promise<void>;
  clear(): Promise<void>;
}

export interface RefreshRequest {
  refresh_token: string;
  device_id: string;
  timestamp: number;
  signature: string;
}

export type RefreshCall = (req: RefreshRequest) => Promise<{ status: number; body: unknown }>;
export type Signer = (message: string) => Promise<string>;

/** Must match TokenIssuer::refreshMessage() on the server. */
export async function refreshMessage(deviceId: string, timestamp: number, refreshToken: string, sha256Hex: (s: string) => Promise<string>): Promise<string> {
  return `refresh.${deviceId}.${timestamp}.${await sha256Hex(refreshToken)}`;
}

export class SessionExpired extends Error {
  constructor(public readonly reason: 'signed_out' | 'device_revoked' | 'token_reused' | 'expired') {
    super(reason);
    this.name = 'SessionExpired';
  }
}

export class Session {
  private pair: TokenPair | null = null;
  private loaded = false;
  private refreshing: Promise<TokenPair> | null = null;

  constructor(
    private readonly storage: SessionStorage,
    private readonly refreshCall: RefreshCall,
    private readonly sign: Signer,
    private readonly sha256Hex: (s: string) => Promise<string>,
    private readonly onEnded: (reason: SessionExpired['reason']) => Promise<void> | void,
    private readonly now: () => number = Date.now,
  ) {}

  async current(): Promise<TokenPair | null> {
    if (!this.loaded) {
      this.pair = await this.storage.load();
      this.loaded = true;
    }
    return this.pair;
  }

  async start(pair: TokenPair): Promise<void> {
    this.pair = pair;
    this.loaded = true;
    await this.storage.save(pair);
  }

  /**
   * A valid access token, refreshing it when it expires within 60 s. Returns null
   * when offline-capable data is all that is available (the caller stays offline).
   */
  async accessToken(): Promise<string | null> {
    const pair = await this.current();
    if (!pair) return null;
    if (Date.parse(pair.expires_at) - this.now() > 60_000) return pair.access_token;

    return (await this.refresh()).access_token;
  }

  /** One refresh at a time, however many requests ask for a token. */
  refresh(): Promise<TokenPair> {
    this.refreshing ??= this.doRefresh().finally(() => {
      this.refreshing = null;
    });
    return this.refreshing;
  }

  /** Offline sign-in is allowed until offline_until (14 days, renewed on refresh). */
  async canOpenOffline(): Promise<boolean> {
    const pair = await this.current();
    return !!pair && Date.parse(pair.offline_until) > this.now();
  }

  async end(reason: SessionExpired['reason']): Promise<void> {
    this.pair = null;
    this.loaded = true;
    await this.storage.clear();
    await this.onEnded(reason);
  }

  private async doRefresh(): Promise<TokenPair> {
    const pair = await this.current();
    if (!pair) throw new SessionExpired('signed_out');
    if (Date.parse(pair.refresh_expires_at) <= this.now()) {
      await this.end('expired');
      throw new SessionExpired('expired');
    }

    const timestamp = Math.floor(this.now() / 1000);
    const signature = await this.sign(await refreshMessage(pair.device_id, timestamp, pair.refresh_token, this.sha256Hex));
    const res = await this.refreshCall({ refresh_token: pair.refresh_token, device_id: pair.device_id, timestamp, signature });

    if (res.status === 200) {
      const next = res.body as TokenPair;
      await this.start(next);
      return next;
    }

    const code = (res.body as { error?: { code?: string } } | undefined)?.error?.code;
    if (res.status === 401) {
      const reason = code === 'device_revoked' ? 'device_revoked' : code === 'token_reused' ? 'token_reused' : 'expired';
      await this.end(reason);
      throw new SessionExpired(reason);
    }

    // Network / server trouble: keep the session; the app works offline meanwhile.
    throw new Error(`refresh failed (${res.status})`);
  }
}
