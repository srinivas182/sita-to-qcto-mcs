import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { Fingerprint, KeyRound, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Button, Field } from '@/components/ui';
import { api, ApiError, errorMessage, json } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { brandingQuery, meQuery } from '@/lib/session';
import { getPasskey, passkeysSupported } from '@/lib/webauthn';
import { AuthLayout } from './AuthLayout';
import { LearnerSignIn } from './LearnerSignIn';

type Step = { kind: 'staff' } | { kind: 'learner' } | { kind: 'mfa'; token: string };

interface LoginResult {
  mode?: 'session';
  mfa_required?: boolean;
  mfa_token?: string;
}

/**
 * One sign-in page for every role. Passkey first (with browser autofill), email +
 * password with two-step fallback, and a phone-PIN path for learners.
 */
export function SignInPage() {
  const t = useT();
  const { data: brand } = useQuery(brandingQuery);
  const [step, setStep] = useState<Step>({ kind: brand?.platform === false ? 'staff' : 'staff' });
  const finish = useFinishSignIn();

  return (
    <AuthLayout>
      {step.kind === 'mfa' ? (
        <MfaStep token={step.token} onBack={() => setStep({ kind: 'staff' })} onDone={finish} />
      ) : step.kind === 'learner' ? (
        <>
          <LearnerSignIn onDone={finish} />
          <SwitchLink icon={<KeyRound className="size-4" />} onClick={() => setStep({ kind: 'staff' })}>
            {t('auth.staff')}
          </SwitchLink>
        </>
      ) : (
        <>
          <StaffSignIn
            onMfa={(token) => setStep({ kind: 'mfa', token })}
            onDone={finish}
            name={brand?.name ?? t('app.name')}
          />
          {brand?.platform ? null : (
            <SwitchLink icon={<Smartphone className="size-4" />} onClick={() => setStep({ kind: 'learner' })}>
              {t('auth.learner')}
            </SwitchLink>
          )}
        </>
      )}
    </AuthLayout>
  );
}

export function useFinishSignIn() {
  const qc = useQueryClient();
  const navigate = useNavigate();
  return async () => {
    await qc.invalidateQueries({ queryKey: meQuery.queryKey });
    await qc.fetchQuery(meQuery);
    const next = new URLSearchParams(window.location.search).get('next');
    await navigate({ to: next && next.startsWith('/') ? next : '/' });
  };
}

function SwitchLink({
  icon,
  onClick,
  children,
}: {
  icon: React.ReactNode;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="mt-6 flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-line py-3 text-small font-medium text-muted hover:border-primary-300 hover:text-primary-700"
    >
      {icon}
      {children}
    </button>
  );
}

function StaffSignIn({
  name,
  onMfa,
  onDone,
}: {
  name: string;
  onMfa: (token: string) => void;
  onDone: () => Promise<void>;
}) {
  const t = useT();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<'password' | 'passkey' | null>(null);
  const conditional = useRef<AbortController | null>(null);

  const passkeyLogin = async (mediation?: CredentialMediationRequirement) => {
    const start = await json<{ session: string; options: Record<string, unknown> }>(
      api.POST('/auth/passkeys/login/options'),
    );
    const credential = await getPasskey(start.options, mediation);
    setBusy('passkey');
    await api.POST('/auth/passkeys/login', { body: { session: start.session, credential, remember } as never });
    await onDone();
  };

  // Browser passkey autofill (as on Google and GitHub): offered in the email field.
  useEffect(() => {
    const pkc = window.PublicKeyCredential as unknown as
      { isConditionalMediationAvailable?: () => Promise<boolean> } | undefined;
    if (!pkc?.isConditionalMediationAvailable) return;
    void pkc.isConditionalMediationAvailable().then((ok) => {
      if (!ok) return;
      conditional.current = new AbortController();
      passkeyLogin('conditional').catch(() => setBusy(null));
    });
    return () => conditional.current?.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setBusy('password');
    try {
      const result = await json<LoginResult>(api.POST('/auth/login', { body: { email, password, remember } }));
      if (result.mfa_required && result.mfa_token) onMfa(result.mfa_token);
      else await onDone();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <h1 className="text-[24px] font-semibold tracking-tight">{t('auth.title', { name })}</h1>
      <p className="mt-1.5 text-body text-muted">{t('auth.subtitle')}</p>

      {passkeysSupported() ? (
        <Button
          type="button"
          size="lg"
          block
          className="mt-7"
          icon={<Fingerprint className="size-5" />}
          loading={busy === 'passkey'}
          onClick={() => {
            setError(null);
            passkeyLogin().catch((err) => {
              setBusy(null);
              if (err instanceof ApiError) setError(errorMessage(err));
            });
          }}
        >
          {t('auth.passkey')}
        </Button>
      ) : null}

      {passkeysSupported() ? (
        <div className="my-6 flex items-center gap-3 text-caption uppercase tracking-wider text-faint">
          <span className="h-px flex-1 bg-line" />
          {t('auth.or')}
          <span className="h-px flex-1 bg-line" />
        </div>
      ) : (
        <div className="h-7" />
      )}

      <form onSubmit={submit} className="grid gap-4" noValidate>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label={t('auth.email')}
          type="email"
          autoComplete="username webauthn"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoFocus
        />
        <Field
          label={t('auth.password')}
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          trailing={
            <Link to="/forgot-password" className="text-small font-medium text-primary-700 hover:underline">
              {t('auth.forgot')}
            </Link>
          }
        />
        <label className="flex items-center gap-2 text-small text-muted">
          <input
            type="checkbox"
            checked={remember}
            onChange={(e) => setRemember(e.target.checked)}
            className="size-4 rounded accent-primary-700"
          />
          {t('auth.remember')}
        </label>
        <Button
          type="submit"
          variant={passkeysSupported() ? 'secondary' : 'primary'}
          size="lg"
          block
          loading={busy === 'password'}
        >
          {t('auth.signIn')}
        </Button>
      </form>
    </>
  );
}

function MfaStep({ token, onBack, onDone }: { token: string; onBack: () => void; onDone: () => Promise<void> }) {
  const t = useT();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/auth/mfa/totp/challenge', { body: { mfa_token: token, code } });
      await onDone();
    } catch (err) {
      setError(errorMessage(err));
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="grid gap-4">
      <h1 className="text-[24px] font-semibold tracking-tight">{t('auth.mfaTitle')}</h1>
      <p className="-mt-2 text-body text-muted">{t('auth.mfaHint')}</p>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field
        label={t('auth.code')}
        inputMode="numeric"
        autoComplete="one-time-code"
        autoFocus
        value={code}
        onChange={(e) => setCode(e.target.value)}
        className="[&_input]:text-center [&_input]:text-[18px] [&_input]:tracking-[.4em]"
      />
      <Button type="submit" size="lg" block loading={busy}>
        {t('auth.verify')}
      </Button>
      <Button type="button" variant="ghost" onClick={onBack}>
        {t('common.back')}
      </Button>
    </form>
  );
}
