import { ApiError, createApi, type Schemas } from '@skills/api-client';

/** Same-origin client: cookie session + CSRF, served from every tenant host. */
export const api = createApi();

export { ApiError };
export type { Schemas };

export type Me = Schemas['MeResource'];

/** Typed JSON for endpoints whose response shape the generator cannot infer fully. */
export async function json<T>(promise: Promise<{ data?: unknown }>): Promise<T> {
  const { data } = await promise;
  return data as T;
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.fields)[0]?.[0];
    return first ?? error.message;
  }
  return 'Something went wrong. Please try again.';
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * Call a path that is built at runtime (e.g. admin vs provider variants of the same
 * screen). Types are asserted by the caller; prefer the typed `api.GET('/literal')`.
 */
export async function call<T>(
  method: Method,
  path: string,
  body?: unknown,
  query?: Record<string, string | number | undefined>,
): Promise<T> {
  const init = { body, params: { query } } as never;
  const fn = api[method] as unknown as (p: string, i: unknown) => Promise<{ data?: unknown }>;
  const { data } = await fn(path, init);
  return data as T;
}
