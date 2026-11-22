import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/lib/i18n';
import { LearnerSignIn } from '@/features/auth/LearnerSignIn';

function respond(status: number, body: unknown) {
  return Promise.resolve(
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  );
}

afterEach(() => vi.restoreAllMocks());

describe('LearnerSignIn', () => {
  it('requests a PIN, then verifies it', async () => {
    document.cookie = 'XSRF-TOKEN=token';
    const calls: string[] = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
      const req = input as Request;
      calls.push(`${req.method} ${new URL(req.url).pathname}`);
      if (req.url.endsWith('/otp/request'))
        return respond(202, { challenge_id: '01ABC', expires_in: 300, resend_after: 30 });
      return respond(200, { mode: 'session' });
    });
    const done = vi.fn(async () => undefined);
    render(
      <I18nProvider>
        <LearnerSignIn onDone={done} />
      </I18nProvider>,
    );

    await userEvent.type(screen.getByLabelText(/mobile, sa id or passport/i), '082 123 4567');
    await userEvent.click(screen.getByRole('button', { name: /send pin/i }));

    const pin = await screen.findByLabelText(/6-digit pin/i);
    expect(screen.getByRole('button', { name: /resend in/i })).toBeDisabled();
    await userEvent.type(pin, '12a3456');
    expect(pin).toHaveValue('123456');
    await userEvent.click(screen.getByRole('button', { name: /^verify$/i }));

    expect(done).toHaveBeenCalled();
    expect(calls).toEqual(['POST /v1/auth/otp/request', 'POST /v1/auth/otp/verify']);
  });

  it('shows the throttle message', async () => {
    document.cookie = 'XSRF-TOKEN=token';
    vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
      respond(429, { error: { code: 'too_many_attempts', message: 'Too many attempts. Try again in 30 seconds.' } }),
    );
    render(
      <I18nProvider>
        <LearnerSignIn onDone={async () => undefined} />
      </I18nProvider>,
    );

    await userEvent.type(screen.getByLabelText(/mobile/i), '0821234567');
    await userEvent.click(screen.getByRole('button', { name: /send pin/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts');
  });
});
