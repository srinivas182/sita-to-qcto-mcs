import { describe, expect, it } from 'vitest';
import { format } from '@/lib/i18n';
import { en } from '@/lib/i18n/en';
import zu from '@/lib/i18n/zu';
import { b64urlToBuffer, bufferToB64url } from '@/lib/webauthn';
import { navFor } from '@/shell/nav';
import type { Me } from '@/lib/api';

const me = (over: Partial<Me>): Me => ({
  user: {
    id: 'u',
    first_name: 'Sekal',
    last_name: 'N',
    email: 's@tym.co.za',
    phone: null,
    id_number: null,
    locale: 'en',
  },
  tenant: null,
  roles: [],
  platform_roles: [],
  permissions: [],
  security: { totp: true, passkeys: 0, mfa_required: false, mfa_setup_required: false },
  support_access: null,
  session: { mode: 'session' },
  ...over,
});

describe('i18n', () => {
  it('interpolates variables', () => {
    expect(format(en['auth.title'], { name: 'Training Young Minds' })).toBe('Sign in to Training Young Minds');
  });

  it('only translates keys that exist in English', () => {
    expect(Object.keys(zu).every((k) => k in en)).toBe(true);
  });
});

describe('webauthn encoding', () => {
  it('round-trips base64url', () => {
    const bytes = new Uint8Array([0, 1, 250, 251, 252, 253, 254, 255]);
    const encoded = bufferToB64url(bytes.buffer);
    expect(encoded).toBe('AAH6-_z9_v8');
    expect(new Uint8Array(b64urlToBuffer(encoded!))).toEqual(bytes);
  });
});

describe('navigation', () => {
  it('shows provider screens only with the permission for them', () => {
    const tenant = { id: 't', code: 'tym', name: 'TYM', status: 'active', branding: {}, billing: null };
    const learner = navFor(me({ tenant, permissions: ['learning.self'] })).map((i) => i.to);
    const owner = navFor(
      me({
        tenant,
        permissions: ['tenant.users.view', 'tenant.settings.manage', 'tenant.audit.view', 'tenant.support.approve'],
      }),
    ).map((i) => i.to);

    expect(learner).toEqual(['/', '/my-course', '/profile', '/security']);
    expect(owner).toEqual(['/', '/users', '/brand', '/support', '/audit', '/profile', '/security']);
  });

  it('shows the platform console off any provider', () => {
    const items = navFor(me({ permissions: ['platform.tenants.view', 'platform.audit.view'] })).map((i) => i.to);
    expect(items).toEqual(['/', '/tenants', '/audit', '/security']);
  });
});
