import { queryOptions, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError, json, type Me } from './api';

export interface Branding {
  name: string;
  code?: string;
  platform: boolean;
  status?: string;
  branding: { logo_url?: string; primary?: string; accent?: string; tagline?: string };
  /** Approved through the accreditation check, with a centre still accredited. */
  verified?: { accreditation_number: string | null; valid_until: string | null; verified_on: string | null } | null;
}

export const brandingQuery = queryOptions({
  queryKey: ['branding'],
  queryFn: () => json<Branding>(api.GET('/branding')),
  staleTime: 5 * 60_000,
});

/** The signed-in person, or null when signed out. */
export const meQuery = queryOptions({
  queryKey: ['me'],
  queryFn: async (): Promise<Me | null> => {
    try {
      return await json<Me>(api.GET('/me'));
    } catch (e) {
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return null;
      throw e;
    }
  },
  staleTime: 60_000,
});

export function useMe(): Me {
  const { data } = useQuery(meQuery);
  if (!data) throw new Error('useMe() used outside the signed-in area');
  return data;
}

export function useCan() {
  const me = useMe();
  return (permission: string) => me.permissions.includes(permission);
}

export function useSignOut() {
  const qc = useQueryClient();
  return async () => {
    await api.POST('/auth/logout').catch(() => undefined);
    qc.clear();
    window.location.assign('/sign-in');
  };
}

export function initials(first: string, last: string): string {
  return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}
