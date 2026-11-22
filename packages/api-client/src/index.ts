/**
 * Typed client for the Skills Ecosystem API (v1).
 *
 * Types are generated from the OpenAPI document the API exports
 * (`php artisan scramble:export` → openapi.json → `pnpm generate`).
 *
 * Web: same-origin cookie session + CSRF (Laravel Sanctum).
 * Mobile: pass `getToken` for Bearer auth and `tenant` for the X-Tenant header.
 */
import createClient, { type Middleware } from 'openapi-fetch';
import type { components, paths } from './schema';

export type { components, paths };
export type Schemas = components['schemas'];

export interface ApiErrorBody {
  error?: { code: string; message: string };
  message?: string;
  errors?: Record<string, string[]>;
}

/** Normalised API error: stable `code`, human `message`, field errors for forms. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: Record<string, string[]> = {},
    public readonly retryAfter: number | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  static async from(response: Response): Promise<ApiError> {
    let body: ApiErrorBody = {};
    try {
      body = (await response.clone().json()) as ApiErrorBody;
    } catch {
      /* not JSON */
    }
    const retry = response.headers.get('Retry-After');
    const code = body.error?.code ?? (response.status === 422 ? 'validation_failed' : `http_${response.status}`);
    const message = body.error?.message ?? body.message ?? response.statusText ?? 'Request failed';

    return new ApiError(response.status, code, message, body.errors ?? {}, retry ? Number(retry) : null);
  }
}

export interface ClientOptions {
  /** Base URL including the version prefix. Default: same origin `/v1`. */
  baseUrl?: string;
  /** Mobile: returns the current access token. */
  getToken?: () => string | null | Promise<string | null>;
  /** Mobile: the provider code chosen on the TP-code step (sent as X-Tenant). */
  tenant?: () => string | null;
  fetch?: typeof fetch;
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

export function createApi(options: ClientOptions = {}) {
  // Resolve relative bases against the page so requests work in every runtime.
  const origin = globalThis.location?.origin ?? 'http://localhost';
  const baseUrl = new URL(options.baseUrl ?? '/v1', origin).toString().replace(/\/$/, '');
  // Look fetch up on every call (not at creation), so polyfills and test doubles apply.
  const fetchImpl: typeof fetch = (input, init) => (options.fetch ?? globalThis.fetch)(input, init);
  let csrfReady: Promise<void> | null = null;

  const ensureCsrf = (): Promise<void> => {
    if (options.getToken || readCookie('XSRF-TOKEN')) return Promise.resolve();
    csrfReady ??= fetchImpl(new URL('/sanctum/csrf-cookie', origin), {
      credentials: 'include',
    }).then(() => undefined);
    return csrfReady;
  };

  const auth: Middleware = {
    async onRequest({ request }) {
      request.headers.set('Accept', 'application/json');
      request.headers.set('X-Requested-With', 'XMLHttpRequest');

      if (options.getToken) {
        const token = await options.getToken();
        if (token) request.headers.set('Authorization', `Bearer ${token}`);
      } else if (MUTATING.has(request.method)) {
        await ensureCsrf();
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf) request.headers.set('X-XSRF-TOKEN', xsrf);
      }

      const tenant = options.tenant?.();
      if (tenant) request.headers.set('X-Tenant', tenant);

      return request;
    },
    async onResponse({ response }) {
      if (!response.ok) throw await ApiError.from(response);
      return response;
    },
  };

  const client = createClient<paths>({ baseUrl, credentials: 'include', fetch: fetchImpl });
  client.use(auth);

  return client;
}

export type Api = ReturnType<typeof createApi>;
