import { ApiError, createApi } from '@skills/api-client';
import { API_BASE_URL } from './config';
import { getTenantCode, session } from './session';

/** Typed API client for the app: Bearer device token + X-Tenant of the chosen provider. */
export const api = createApi({
  baseUrl: API_BASE_URL,
  getToken: async () => session.accessToken().catch(() => null),
  tenant: getTenantCode,
});

export { ApiError };

export async function json<T>(promise: Promise<{ data?: unknown }>): Promise<T> {
  const { data } = await promise;
  return data as T;
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return Object.values(error.fields)[0]?.[0] ?? error.message;
  return 'You seem to be offline. Check your connection and try again.';
}
