import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Fingerprint, KeyRound, Laptop, ShieldCheck, Smartphone, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  useToast,
} from '@/components/ui';
import { api, call, errorMessage, json } from '@/lib/api';
import { meQuery, useMe } from '@/lib/session';
import { createPasskey, passkeysSupported } from '@/lib/webauthn';

interface Passkey {
  id: string;
  name: string;
  created_at: string | null;
  last_used_at: string | null;
}
interface Device {
  id: string;
  platform: string;
  name: string;
  last_seen_at: string | null;
  current: boolean;
}

export function SecurityPage() {
  const me = useMe();

  return (
    <>
      <PageHeader
        eyebrow="Account"
        title="Security"
        description="Passkeys are the fastest and safest way to sign in. Add one on each device you use."
      />
      {me.security.mfa_setup_required ? (
        <Alert tone="warning" className="mb-5">
          Your role requires two-step verification. Add a passkey or an authenticator app to unlock the console.
        </Alert>
      ) : null}
      <div className="grid gap-5 lg:grid-cols-2">
        <PasskeysCard />
        <AuthenticatorCard />
        <DevicesCard />
      </div>
    </>
  );
}

function PasskeysCard() {
  const qc = useQueryClient();
  const toast = useToast();
  const list = useQuery({
    queryKey: ['passkeys'],
    queryFn: () => call<{ data: Passkey[] }>('GET', '/auth/passkeys').then((r) => r.data),
  });
  const [busy, setBusy] = useState(false);

  const add = async () => {
    setBusy(true);
    try {
      const options = await json<Record<string, unknown>>(api.POST('/auth/passkeys/options'));
      const credential = await createPasskey(options);
      const name = /iPhone|iPad/.test(navigator.userAgent)
        ? 'iPhone'
        : /Android/.test(navigator.userAgent)
          ? 'Android phone'
          : /Mac/.test(navigator.userAgent)
            ? 'Mac'
            : 'This device';
      await api.POST('/auth/passkeys/register', { body: { credential, name } as never });
      toast('Passkey added');
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['passkeys'] }),
        qc.invalidateQueries({ queryKey: meQuery.queryKey }),
      ]);
    } catch (e) {
      if (!(e instanceof Error && e.message === 'cancelled')) toast(errorMessage(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const remove = useMutation({
    mutationFn: (id: string) => call('DELETE', `/auth/passkeys/${id}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['passkeys'] }),
  });

  return (
    <Card>
      <CardHeader
        title="Passkeys"
        subtitle="Face ID, fingerprint or Windows Hello. Phishing-proof."
        action={
          passkeysSupported() ? (
            <Button size="sm" icon={<Fingerprint className="size-4" />} loading={busy} onClick={() => void add()}>
              Add passkey
            </Button>
          ) : null
        }
      />
      {!list.data?.length ? (
        <EmptyState icon={<Fingerprint className="size-5" />} title="No passkeys yet">
          Add one to sign in with a touch, no password needed.
        </EmptyState>
      ) : (
        <ul className="divide-y divide-line-soft">
          {list.data.map((p) => (
            <li key={p.id} className="flex items-center gap-3 py-3">
              <Fingerprint className="size-5 text-primary-700" />
              <div className="flex-1">
                <div className="font-medium">{p.name}</div>
                <div className="text-small text-muted">
                  {p.last_used_at ? `Last used ${new Date(p.last_used_at).toLocaleDateString()}` : 'Not used yet'}
                </div>
              </div>
              <Button
                size="sm"
                variant="ghost"
                icon={<Trash2 className="size-3.5" />}
                onClick={() => remove.mutate(p.id)}
              >
                Remove
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function AuthenticatorCard() {
  const me = useMe();
  const qc = useQueryClient();
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!setup) return;
    // The QR library is only loaded on this screen.
    void import('qrcode')
      .then((QR) => QR.toDataURL(setup.otpauth_url, { margin: 1, width: 220, color: { dark: '#1e1b4b' } }))
      .then(setQr);
  }, [setup]);

  const begin = async () => {
    setError(null);
    try {
      setSetup(await json<{ secret: string; otpauth_url: string }>(api.POST('/auth/mfa/totp/enable')));
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  const confirm = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await json<{ recovery_codes: string[] }>(api.POST('/auth/mfa/totp/confirm', { body: { code } }));
      setCodes(r.recovery_codes);
      setSetup(null);
      await qc.invalidateQueries({ queryKey: meQuery.queryKey });
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader
        title="Authenticator app"
        subtitle="Google Authenticator, Microsoft Authenticator or 1Password."
        action={
          me.security.totp ? (
            <Badge tone="success" dot>
              On
            </Badge>
          ) : (
            <Button size="sm" variant="secondary" icon={<KeyRound className="size-4" />} onClick={() => void begin()}>
              Set up
            </Button>
          )
        }
      />
      {error && !setup ? <Alert tone="danger">{error}</Alert> : null}
      {me.security.totp ? (
        <p className="text-small text-muted">You will be asked for a code after your password.</p>
      ) : (
        <p className="text-small text-muted">Adds a 6-digit code after your password.</p>
      )}

      <Dialog
        open={!!setup}
        onOpenChange={(o) => !o && setSetup(null)}
        title="Set up your authenticator"
        description="Scan the code with your app, then enter the 6-digit code it shows."
        footer={
          <Button loading={busy} disabled={code.length < 6} onClick={() => void confirm()}>
            Verify and turn on
          </Button>
        }
      >
        <div className="grid gap-4 sm:grid-cols-[220px_1fr] sm:items-center">
          <div className="grid size-[220px] place-items-center rounded-xl border border-line bg-white">
            {qr ? <img src={qr} alt="QR code for your authenticator app" width={220} height={220} /> : null}
          </div>
          <div className="grid gap-3">
            <div className="text-small text-muted">Can’t scan? Enter this key:</div>
            <code className="break-all rounded-md bg-surface-muted p-2 font-mono text-[12px]">{setup?.secret}</code>
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <Field
              label="Code"
              inputMode="numeric"
              autoComplete="one-time-code"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            />
          </div>
        </div>
      </Dialog>

      <Dialog
        open={!!codes}
        onOpenChange={(o) => !o && setCodes(null)}
        title="Save your recovery codes"
        description="Each code works once if you lose your phone. They will not be shown again."
        footer={<Button onClick={() => setCodes(null)}>I have saved them</Button>}
      >
        <div className="grid grid-cols-2 gap-2 rounded-lg bg-surface-muted p-4 font-mono text-[13px]">
          {codes?.map((c) => (
            <span key={c}>{c}</span>
          ))}
        </div>
        <Button
          className="mt-3"
          size="sm"
          variant="secondary"
          onClick={() => void navigator.clipboard?.writeText(codes?.join('\n') ?? '')}
        >
          Copy all
        </Button>
      </Dialog>
    </Card>
  );
}

function DevicesCard() {
  const qc = useQueryClient();
  const me = useMe();
  const list = useQuery({
    queryKey: ['devices'],
    queryFn: () => call<{ data: Device[] }>('GET', '/auth/devices').then((r) => r.data),
    enabled: !!me.tenant,
  });
  const revoke = useMutation({
    mutationFn: (id: string) => call('DELETE', `/auth/devices/${id}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['devices'] }),
  });

  if (!me.tenant) return null;

  return (
    <Card className="lg:col-span-2">
      <CardHeader
        title="Signed-in phones"
        subtitle="Signing a phone out also removes the data stored on it the next time it connects."
      />
      {!list.data?.length ? (
        <EmptyState icon={<Smartphone className="size-5" />} title="No phones signed in" />
      ) : (
        <ul className="divide-y divide-line-soft">
          {list.data.map((d) => (
            <li key={d.id} className="flex items-center gap-3 py-3">
              {d.platform === 'ios' || d.platform === 'android' ? (
                <Smartphone className="size-5 text-muted" />
              ) : (
                <Laptop className="size-5 text-muted" />
              )}
              <div className="flex-1">
                <div className="font-medium">
                  {d.name} {d.current ? <Badge tone="primary">This device</Badge> : null}
                </div>
                <div className="text-small text-muted">
                  {d.last_seen_at ? `Active ${new Date(d.last_seen_at).toLocaleString()}` : ''}
                </div>
              </div>
              <Button
                size="sm"
                variant="ghost"
                icon={<ShieldCheck className="size-3.5" />}
                onClick={() => revoke.mutate(d.id)}
              >
                Sign out & wipe
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
