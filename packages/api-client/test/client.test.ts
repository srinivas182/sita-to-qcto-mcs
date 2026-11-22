import { describe, expect, it, vi } from 'vitest';
import { ApiError, createApi } from '../src';

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

describe('createApi', () => {
  it('sends bearer token and tenant header for the mobile app', async () => {
    const fetch = vi.fn(async (req: Request) => {
      expect(req.headers.get('Authorization')).toBe('Bearer abc');
      expect(req.headers.get('X-Tenant')).toBe('tym');
      expect(req.headers.get('Accept')).toBe('application/json');
      return jsonResponse(200, { name: 'TYM' });
    });
    const api = createApi({ baseUrl: 'https://api.skills.test/v1', getToken: () => 'abc', tenant: () => 'tym', fetch: fetch as never });

    const { data } = await api.GET('/branding');
    expect(data).toEqual({ name: 'TYM' });
  });

  it('turns error responses into ApiError with code, fields and retry time', async () => {
    const fetch = vi.fn(async () =>
      jsonResponse(429, { error: { code: 'too_many_attempts', message: 'Too many attempts.' } }, { 'Retry-After': '30' }),
    );
    const api = createApi({ baseUrl: 'https://x.test/v1', getToken: () => null, fetch: fetch as never });

    const error = await api.POST('/auth/login', { body: { email: 'a@b.co', password: 'x' } }).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 429, code: 'too_many_attempts', retryAfter: 30 });
  });

  it('exposes validation errors', async () => {
    const fetch = vi.fn(async () => jsonResponse(422, { message: 'Invalid', errors: { email: ['Required'] } }));
    const api = createApi({ baseUrl: 'https://x.test/v1', getToken: () => null, fetch: fetch as never });

    const error = (await api.POST('/auth/password/forgot', { body: { email: '' } }).catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('validation_failed');
    expect(error.fields.email).toEqual(['Required']);
  });
});
