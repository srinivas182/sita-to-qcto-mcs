import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Field } from '@/components/ui';
import { api, errorMessage, json } from '@/lib/api';
import { useT } from '@/lib/i18n';

/** Phone PIN for learners: mobile number, SA ID or passport → PIN by WhatsApp/SMS. */
export function LearnerSignIn({ onDone }: { onDone: () => Promise<void> }) {
  const t = useT();
  const [identifier, setIdentifier] = useState('');
  const [challenge, setChallenge] = useState<{ id: string; resendAt: number } | null>(null);
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (!challenge) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [challenge]);

  // WebOTP: fill the PIN automatically from the SMS on supporting phones.
  useEffect(() => {
    if (!challenge || !('OTPCredential' in window)) return;
    const ac = new AbortController();
    navigator.credentials
      .get({ otp: { transport: ['sms'] }, signal: ac.signal } as CredentialRequestOptions)
      .then((c) => {
        const code = (c as unknown as { code?: string } | null)?.code;
        if (code) setPin(code);
      })
      .catch(() => undefined);
    return () => ac.abort();
  }, [challenge]);

  const send = async (e?: FormEvent) => {
    e?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await json<{ challenge_id: string; resend_after: number }>(
        api.POST('/auth/otp/request', { body: { identifier } }),
      );
      setChallenge({ id: r.challenge_id, resendAt: Date.now() + r.resend_after * 1000 });
      setPin('');
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const verify = async (e: FormEvent) => {
    e.preventDefault();
    if (!challenge) return;
    setBusy(true);
    setError(null);
    try {
      await api.POST('/auth/otp/verify', { body: { challenge_id: challenge.id, code: pin } });
      await onDone();
    } catch (err) {
      setError(errorMessage(err));
      setBusy(false);
    }
  };

  const wait = challenge ? Math.max(0, Math.ceil((challenge.resendAt - now) / 1000)) : 0;

  return (
    <div className="grid gap-4">
      <h1 className="text-[24px] font-semibold tracking-tight">{t('auth.learnerTitle')}</h1>
      <p className="-mt-2 text-body text-muted">{t('auth.learnerHint')}</p>
      {error ? <Alert tone="danger">{error}</Alert> : null}

      {!challenge ? (
        <form onSubmit={send} className="grid gap-4">
          <Field
            label={t('auth.identifier')}
            inputMode="tel"
            autoComplete="tel"
            autoFocus
            required
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
            placeholder="082 123 4567"
          />
          <Button type="submit" size="lg" block loading={busy}>
            {t('auth.sendPin')}
          </Button>
        </form>
      ) : (
        <form onSubmit={verify} className="grid gap-4">
          <Alert tone="info">{t('auth.pinSent')}</Alert>
          <Field
            label={t('auth.pin')}
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            autoFocus
            value={pin}
            onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
            className="[&_input]:text-center [&_input]:text-[20px] [&_input]:tracking-[.5em]"
          />
          <Button type="submit" size="lg" block loading={busy} disabled={pin.length !== 6}>
            {t('auth.verify')}
          </Button>
          <Button type="button" variant="ghost" disabled={wait > 0 || busy} onClick={() => void send()}>
            {wait > 0 ? t('auth.resendIn', { s: wait }) : t('auth.resend')}
          </Button>
        </form>
      )}
    </div>
  );
}
