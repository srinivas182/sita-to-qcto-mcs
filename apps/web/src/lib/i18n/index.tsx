import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { en, type MessageKey } from './en';

/**
 * Lightweight i18n: English is the source; other South African languages are loaded
 * on demand and fall back to English key by key, so partial translations are safe.
 */
export const locales = {
  en: 'English',
  zu: 'isiZulu',
  xh: 'isiXhosa',
  st: 'Sesotho',
  tn: 'Setswana',
  af: 'Afrikaans',
} as const;

export type Locale = keyof typeof locales;
type Dict = Partial<Record<MessageKey, string>>;

const loaders: Record<Exclude<Locale, 'en'>, () => Promise<{ default: Dict }>> = {
  zu: () => import('./zu'),
  xh: () => import('./xh'),
  st: () => import('./st'),
  tn: () => import('./tn'),
  af: () => import('./af'),
};

export function format(template: string, vars?: Record<string, string | number>): string {
  return vars ? template.replace(/\{(\w+)\}/g, (_, k: string) => String(vars[k] ?? `{${k}}`)) : template;
}

interface I18n {
  locale: Locale;
  setLocale: (locale: Locale) => void;
  t: (key: MessageKey, vars?: Record<string, string | number>) => string;
}

const Ctx = createContext<I18n | null>(null);

function initialLocale(): Locale {
  try {
    const saved = localStorage.getItem('locale');
    if (saved && saved in locales) return saved as Locale;
  } catch {
    /* storage unavailable */
  }
  return 'en';
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setState] = useState<Locale>(initialLocale);
  const [dict, setDict] = useState<Dict>({});

  const setLocale = useCallback((next: Locale) => {
    try {
      localStorage.setItem('locale', next);
    } catch {
      /* ignore */
    }
    document.documentElement.lang = next;
    setState(next);
    if (next === 'en') setDict({});
    else void loaders[next]().then((m) => setDict(m.default));
  }, []);

  const value = useMemo<I18n>(
    () => ({ locale, setLocale, t: (key, vars) => format(dict[key] ?? en[key], vars) }),
    [locale, dict, setLocale],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useI18n(): I18n {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useI18n outside I18nProvider');
  return ctx;
}

export const useT = () => useI18n().t;
