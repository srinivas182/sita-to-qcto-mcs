import NetInfo from '@react-native-community/netinfo';
import { useColorScheme } from 'nativewind';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';
import type { TokenPair } from '@/core/session';
import { RELOCK_AFTER_MS } from '@/core/lock';
import { deleteDatabase } from './db';
import { deviceLocale, translate, type Key, type Locale } from './i18n';
import { syncDecisions, waitingDecisions } from './assess';
import { syncLogbook, waiting } from './logbook';
import { outbox, sendOutboxItem } from './outbox';
import { PERSONAL_KEYS, secure } from './secure';
import { onSessionEnded, session, setTenantCode } from './session';

export interface Tenant {
  code: string;
  name: string;
  branding: { primary?: string; logo_url?: string; tagline?: string };
}

export type Phase = 'loading' | 'welcome' | 'tenant' | 'signed-out' | 'setup-lock' | 'locked' | 'ready';
export type ThemeChoice = 'system' | 'light' | 'dark';

interface AppValue {
  phase: Phase;
  tenant: Tenant | null;
  online: boolean;
  pending: number;
  notice: string | null;
  locale: Locale;
  theme: ThemeChoice;
  t: (key: Key, vars?: Record<string, string | number>) => string;
  setLocale: (l: Locale) => Promise<void>;
  setTheme: (t: ThemeChoice) => Promise<void>;
  continueFromWelcome: () => void;
  chooseTenant: (tenant: Tenant) => Promise<void>;
  changeTenant: () => Promise<void>;
  signedIn: (pair: TokenPair) => Promise<void>;
  lockConfigured: () => void;
  unlocked: () => void;
  signOut: () => Promise<void>;
  wipe: (notice?: Key) => Promise<void>;
  syncNow: () => Promise<void>;
  dismissNotice: () => void;
}

const Ctx = createContext<AppValue | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<Phase>('loading');
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [online, setOnline] = useState(true);
  const [pending, setPending] = useState(0);
  const [notice, setNotice] = useState<string | null>(null);
  const [locale, setLocaleState] = useState<Locale>(deviceLocale());
  const [theme, setThemeState] = useState<ThemeChoice>('system');
  const { setColorScheme } = useColorScheme();
  const backgroundedAt = useRef<number | null>(null);

  const t = useCallback((key: Key, vars?: Record<string, string | number>) => translate(locale, key, vars), [locale]);

  const wipe = useCallback(
    async (key?: Key) => {
      // Remote wipe / sign-out: local database and personal keys go; provider choice stays.
      await deleteDatabase();
      await Promise.all(PERSONAL_KEYS.filter((k) => k !== 'tenant').map((k) => secure.remove(k)));
      setPending(0);
      setNotice(key ? translate(locale, key) : null);
      setPhase('signed-out');
    },
    [locale],
  );

  // Boot: restore provider, session, lock and preferences.
  useEffect(() => {
    void (async () => {
      const [savedTenant, savedLocale, savedTheme, lockMethod] = await Promise.all([
        secure.getJson<Tenant>('tenant'),
        secure.get('locale'),
        secure.get('theme'),
        secure.get('lock_method'),
      ]);
      if (savedLocale) setLocaleState(savedLocale as Locale);
      if (savedTheme) {
        setThemeState(savedTheme as ThemeChoice);
        setColorScheme(savedTheme as ThemeChoice);
      }
      if (!savedTenant) return setPhase('welcome');

      setTenant(savedTenant);
      setTenantCode(savedTenant.code);

      const current = await session.current();
      if (!current) return setPhase('signed-out');

      const net = await NetInfo.fetch();
      if (!net.isConnected && !(await session.canOpenOffline())) {
        setNotice(translate((savedLocale as Locale) ?? 'en', 'notice.offlineExpired'));
        return setPhase('signed-out');
      }

      setPhase(lockMethod ? 'locked' : 'setup-lock');
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Server says the device was revoked or the session ended.
  useEffect(() => {
    onSessionEnded((reason) => {
      if (reason === 'signed_out') return; // a voluntary sign-out wipes on its own
      void wipe(reason === 'device_revoked' || reason === 'token_reused' ? 'notice.revoked' : 'notice.expired');
    });
  }, [wipe]);

  const syncNow = useCallback(async () => {
    if (phase !== 'ready') return;
    await outbox.process(sendOutboxItem).catch(() => undefined);
    const logbook = await syncLogbook().catch(() => waiting().length);
    const decisions = await syncDecisions().catch(() => waitingDecisions().length);
    setPending((await outbox.pending()).length + logbook + decisions);
  }, [phase]);

  // Connectivity: banner + sync when the connection returns.
  useEffect(() => {
    return NetInfo.addEventListener((state) => {
      const isOnline = !!state.isConnected && state.isInternetReachable !== false;
      setOnline(isOnline);
      if (isOnline) void syncNow();
    });
  }, [syncNow]);

  // Re-lock after 5 minutes in the background; sync when returning.
  useEffect(() => {
    const sub = AppState.addEventListener('change', async (state) => {
      if (state === 'background') backgroundedAt.current = Date.now();
      if (state === 'active') {
        const away = backgroundedAt.current ? Date.now() - backgroundedAt.current : 0;
        backgroundedAt.current = null;
        if (phase === 'ready' && away > RELOCK_AFTER_MS && (await secure.get('lock_method'))) setPhase('locked');
        else void syncNow();
      }
    });
    return () => sub.remove();
  }, [phase, syncNow]);

  // Periodic sync while open.
  useEffect(() => {
    if (phase !== 'ready') return;
    void syncNow();
    const id = setInterval(() => void syncNow(), 60_000);
    return () => clearInterval(id);
  }, [phase, syncNow]);

  const value = useMemo<AppValue>(
    () => ({
      phase,
      tenant,
      online,
      pending,
      notice,
      locale,
      theme,
      t,
      setLocale: async (l) => {
        setLocaleState(l);
        await secure.set('locale', l);
      },
      setTheme: async (choice) => {
        setThemeState(choice);
        setColorScheme(choice);
        await secure.set('theme', choice);
      },
      continueFromWelcome: () => setPhase('tenant'),
      chooseTenant: async (next) => {
        await secure.setJson('tenant', next);
        setTenant(next);
        setTenantCode(next.code);
        setPhase('signed-out');
      },
      changeTenant: async () => {
        await wipe();
        await secure.remove('tenant');
        setTenant(null);
        setTenantCode(null);
        setPhase('tenant');
      },
      signedIn: async (pair) => {
        await session.start(pair);
        setNotice(null);
        setPhase((await secure.get('lock_method')) ? 'ready' : 'setup-lock');
      },
      lockConfigured: () => setPhase('ready'),
      unlocked: () => setPhase('ready'),
      signOut: async () => {
        const { api } = await import('./api');
        await api.POST('/auth/logout').catch(() => undefined);
        await session.end('signed_out').catch(() => undefined);
        await wipe();
      },
      wipe,
      syncNow,
      dismissNotice: () => setNotice(null),
    }),
    [phase, tenant, online, pending, notice, locale, theme, t, setColorScheme, wipe, syncNow],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useApp(): AppValue {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useApp outside AppProvider');
  return ctx;
}
