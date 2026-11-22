import { useQuery } from '@tanstack/react-query';
import { api, json } from './api';
import { cache } from './db';

export interface Me {
  user: { first_name: string; last_name: string; phone: string | null; email: string | null };
  tenant: { name: string } | null;
  roles: Array<{ role: string; label: string }>;
}

/** /me with an encrypted offline copy: the home screen renders without a network. */
export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      try {
        const me = await json<Me>(api.GET('/me'));
        await cache.set('me', me);
        return me;
      } catch (e) {
        const cached = await cache.get<Me>('me');
        if (cached) return cached;
        throw e;
      }
    },
  });
}
