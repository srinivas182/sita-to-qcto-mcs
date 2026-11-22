import type { Tenant } from './app';
import { API_BASE_URL } from './config';

/** Resolve a provider code via the public branding endpoint (X-Tenant on the API host). */
export async function lookupTenant(code: string): Promise<Tenant | null> {
  const clean = code.trim().toLowerCase();
  if (!/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/.test(clean)) return null;

  const res = await fetch(`${API_BASE_URL}/branding`, { headers: { Accept: 'application/json', 'X-Tenant': clean } });
  if (!res.ok) return null;

  const body = (await res.json()) as { name: string; code?: string; platform: boolean; branding?: Tenant['branding'] };
  if (body.platform) return null;

  return { code: body.code ?? clean, name: body.name, branding: body.branding ?? {} };
}

/** Accepts "tym", "skills://tenant/tym" or a link like "https://…/join?tp=tym". */
export function codeFromQr(data: string): string | null {
  const value = data.trim();
  try {
    const url = new URL(value);
    const fromParam = url.searchParams.get('tp');
    if (fromParam) return fromParam;
    const last = url.pathname.split('/').filter(Boolean).pop();
    return last ?? url.host ?? null;
  } catch {
    return /^[a-z0-9-]{3,30}$/i.test(value) ? value : null;
  }
}
