import { useQuery } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { useState, type FormEvent } from 'react';
import { Alert, Button, Field, Skeleton } from '@/components/ui';
import { api, errorMessage, json } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { AuthLayout } from './AuthLayout';
import { useFinishSignIn } from './SignInPage';

interface InvitationView {
  tenant: string;
  role_label: string;
  email: string | null;
  first_name: string | null;
  last_name: string | null;
}

export function AcceptInvitationPage() {
  const t = useT();
  const { token } = useParams({ strict: false }) as { token: string };
  const finish = useFinishSignIn();
  const invitation = useQuery({
    queryKey: ['invitation', token],
    queryFn: () => json<InvitationView>(api.GET('/invitations/{token}', { params: { path: { token } } })),
    retry: false,
  });
  const [form, setForm] = useState({ first_name: '', last_name: '', password: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/invitations/{token}/accept', {
        params: { path: { token } },
        body: {
          first_name: form.first_name || invitation.data?.first_name || undefined,
          last_name: form.last_name || invitation.data?.last_name || undefined,
          password: form.password || undefined,
        } as never,
      });
      await finish();
    } catch (err) {
      setError(errorMessage(err));
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      {invitation.isPending ? (
        <div className="grid gap-3">
          <Skeleton className="h-8 w-2/3" />
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      ) : invitation.isError ? (
        <Alert tone="danger">{t('invite.invalid')}</Alert>
      ) : (
        <form onSubmit={submit} className="grid gap-4">
          <h1 className="text-[24px] font-semibold tracking-tight">
            {t('invite.title', { tenant: invitation.data.tenant })}
          </h1>
          <p className="-mt-2 text-body text-muted">{t('invite.as', { role: invitation.data.role_label })}</p>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="First name"
              autoComplete="given-name"
              required
              defaultValue={invitation.data.first_name ?? ''}
              onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            />
            <Field
              label="Last name"
              autoComplete="family-name"
              required
              defaultValue={invitation.data.last_name ?? ''}
              onChange={(e) => setForm({ ...form, last_name: e.target.value })}
            />
          </div>
          {invitation.data.email ? (
            <Field
              label={t('auth.password')}
              type="password"
              autoComplete="new-password"
              hint={t('auth.passwordRule')}
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
            />
          ) : null}
          <Button type="submit" size="lg" block loading={busy}>
            {t('invite.accept')}
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
