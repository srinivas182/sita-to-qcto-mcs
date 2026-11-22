import { Link } from '@tanstack/react-router';
import { useState, type FormEvent } from 'react';
import { Alert, Button, Field } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { AuthLayout } from './AuthLayout';

export function ForgotPasswordPage() {
  const t = useT();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/auth/password/forgot', { body: { email } });
      setSent(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      <form onSubmit={submit} className="grid gap-4">
        <h1 className="text-[24px] font-semibold tracking-tight">{t('auth.forgotTitle')}</h1>
        <p className="-mt-2 text-body text-muted">{t('auth.forgotHint')}</p>
        {sent ? <Alert tone="success">{t('auth.linkSent')}</Alert> : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label={t('auth.email')}
          type="email"
          autoComplete="email"
          required
          autoFocus
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <Button type="submit" size="lg" block loading={busy}>
          {t('auth.sendLink')}
        </Button>
        <Link to="/sign-in" className="text-center text-small font-medium text-primary-700 hover:underline">
          {t('common.back')}
        </Link>
      </form>
    </AuthLayout>
  );
}

export function ResetPasswordPage() {
  const t = useT();
  const params = new URLSearchParams(window.location.search);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/auth/password/reset', {
        body: {
          email: params.get('email') ?? '',
          token: params.get('token') ?? '',
          password,
          password_confirmation: confirm,
        },
      });
      setDone(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      {done ? (
        <div className="grid gap-4">
          <Alert tone="success">{t('auth.passwordChanged')}</Alert>
          <Link to="/sign-in" className="text-center font-medium text-primary-700">
            {t('auth.signIn')}
          </Link>
        </div>
      ) : (
        <form onSubmit={submit} className="grid gap-4">
          <h1 className="text-[24px] font-semibold tracking-tight">{t('auth.forgotTitle')}</h1>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Field
            label={t('auth.newPassword')}
            type="password"
            autoComplete="new-password"
            hint={t('auth.passwordRule')}
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Field
            label={t('auth.confirmPassword')}
            type="password"
            autoComplete="new-password"
            required
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
          />
          <Button type="submit" size="lg" block loading={busy}>
            {t('auth.setPassword')}
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
