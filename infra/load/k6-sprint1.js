// k6 load test for Sprint 1 budgets (run against staging with Octane, not the dev server).
//
//   k6 run -e BASE=https://api.staging.skillsecosystem.co.za -e TENANT=tym \
//          -e TOKEN=<device access token> infra/load/k6-sprint1.js
//
// Budgets (Sprint 1 spec §4): reads p95 < 150 ms, writes p95 < 250 ms, error rate < 1 %.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE;
const headers = { Accept: 'application/json', 'X-Tenant': __ENV.TENANT, Authorization: `Bearer ${__ENV.TOKEN}` };

export const options = {
  scenarios: {
    me: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '3m', preAllocatedVUs: 100, exec: 'me' },
    branding: { executor: 'constant-arrival-rate', rate: 100, timeUnit: '1s', duration: '3m', preAllocatedVUs: 50, exec: 'branding' },
    signIn: { executor: 'constant-arrival-rate', rate: 5, timeUnit: '1s', duration: '3m', preAllocatedVUs: 20, exec: 'signIn' },
  },
  thresholds: {
    'http_req_duration{scenario:me}': ['p(95)<150'],
    'http_req_duration{scenario:branding}': ['p(95)<150'],
    'http_req_duration{scenario:signIn}': ['p(95)<250'],
    http_req_failed: ['rate<0.01'],
  },
};

export function me() {
  check(http.get(`${BASE}/v1/me`, { headers }), { 'me 200': (r) => r.status === 200 });
}

export function branding() {
  check(http.get(`${BASE}/v1/branding`, { headers: { Accept: 'application/json', 'X-Tenant': __ENV.TENANT } }), { 'branding 200': (r) => r.status === 200 });
}

// DEVICE_KEY: any base64 Ed25519 public key. Sign-in uses a load-test account excluded from lock-out on staging (LOADTEST_EMAIL/PASSWORD).
export function signIn() {
  const res = http.post(
    `${BASE}/v1/auth/login`,
    JSON.stringify({ email: __ENV.LOADTEST_EMAIL, password: __ENV.LOADTEST_PASSWORD, device: { platform: 'android', name: 'k6', public_key: __ENV.DEVICE_KEY } }),
    { headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Tenant': __ENV.TENANT } },
  );
  check(res, { 'sign-in ok': (r) => r.status === 200 });
}
