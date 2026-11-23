import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { CheckCircle2, Download, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Field, Skeleton } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { AgreementText } from './AgreementText';
import { PublicLayout } from './PublicLayout';
import { ROLE_LABEL, signerState, when, type SignerRole } from './types';

interface SignView {
  agreement: {
    title: string;
    provider: string;
    status: string;
    signature_level: string;
    body: string;
    text_sha256: string;
    expires_at: string | null;
    verify_url: string;
    has_document: boolean;
  };
  you: { name: string; role: SignerRole; status: string; pin_to: string | null; signed_at: string | null };
  parties: { role: SignerRole; name: string; status: string }[];
}

const CHANNEL: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'email' };

/** Public: read and sign an agreement on a phone, confirmed with a one-time PIN. */
export function SignPage() {
  const { token } = useParams({ strict: false }) as { token: string };
  const qc = useQueryClient();
  const key = ['sign', token];
  const q = useQuery({
    queryKey: key,
    queryFn: () => call<{ data: SignView }>('GET', `/sign/${token}`).then((r) => r.data),
    retry: false,
  });
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [form, setForm] = useState({ code: '', name: '', agree: false });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { status: [errorMessage(e)] });
  const done = (r: { data: SignView }) => {
    setErrors({});
    qc.setQueryData(key, r.data);
  };
  const code = useMutation({
    mutationFn: () => call<{ data: { channel: string; destination: string | null } }>('POST', `/sign/${token}/code`),
    onSuccess: (r) => {
      setErrors({});
      setSentTo(`${CHANNEL[r.data.channel] ?? r.data.channel} to ${r.data.destination ?? 'you'}`);
    },
    onError: fail,
  });
  const sign = useMutation({
    mutationFn: () => call<{ data: SignView }>('POST', `/sign/${token}`, form),
    onSuccess: done,
    onError: fail,
  });
  const decline = useMutation({
    mutationFn: () => call<{ data: SignView }>('POST', `/sign/${token}/decline`, { reason }),
    onSuccess: done,
    onError: fail,
  });

  if (q.isPending)
    return (
      <PublicLayout>
        <Skeleton className="h-64" />
      </PublicLayout>
    );
  if (q.isError)
    return (
      <PublicLayout>
        <Card>
          <h1 className="text-[20px] font-semibold">This link no longer works</h1>
          <p className="mt-2 text-muted">
            A newer link may have been sent to you, or the agreement was withdrawn. Ask the training provider to send it
            again.
          </p>
        </Card>
      </PublicLayout>
    );

  const { agreement: a, you, parties } = q.data;
  const err = (k: string) => errors[k]?.[0] ?? null;
  const open = a.status === 'sent' && you.status === 'pending' && a.signature_level === 'simple';

  return (
    <PublicLayout>
      <div>
        <p className="text-small text-muted">{a.provider} asks you to sign</p>
        <h1 className="text-[22px] font-semibold tracking-tight text-ink">{a.title}</h1>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {parties.map((p) => (
            <Badge key={p.role} tone={signerState[p.status]?.tone ?? 'neutral'}>
              {ROLE_LABEL[p.role]}: {p.name} · {signerState[p.status]?.label ?? p.status}
            </Badge>
          ))}
        </div>
      </div>

      <Card>
        <div className="max-h-[60vh] overflow-y-auto pr-1" tabIndex={0} aria-label="Agreement text">
          <AgreementText text={a.body} />
        </div>
      </Card>

      {you.status === 'signed' ? (
        <Card>
          <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-success" />
            <div>
              <h2 className="font-semibold text-ink">You signed on {when(you.signed_at)}</h2>
              <p className="text-small text-muted">
                {a.status === 'completed'
                  ? 'Everyone has signed. Download your copy below.'
                  : 'We let you know when everyone has signed, with a link to your copy.'}
              </p>
              {a.has_document ? (
                <a
                  href={`/v1/sign/${token}/document`}
                  className="mt-3 inline-flex h-10 items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-small font-medium text-white hover:bg-primary-700"
                >
                  <Download className="size-4" /> Download the signed copy
                </a>
              ) : null}
            </div>
          </div>
        </Card>
      ) : you.status === 'declined' || a.status === 'declined' ? (
        <Alert tone="warning">This agreement was declined. The training provider will be in touch.</Alert>
      ) : !open ? (
        <Alert tone="info">
          {a.status === 'expired'
            ? 'This link has expired. Ask the training provider to send it again.'
            : 'This agreement can no longer be signed here.'}
        </Alert>
      ) : (
        <Card>
          <h2 className="mb-1 font-semibold text-ink">Sign as {you.name}</h2>
          <p className="mb-4 text-small text-muted">
            <ShieldCheck className="mr-1 inline size-4 align-text-bottom" />
            We send a one-time PIN to {you.pin_to ?? 'you'} to confirm it is you.
          </p>
          {err('status') ? <Alert tone="danger">{err('status')}</Alert> : null}
          {!sentTo ? (
            <Button className="w-full sm:w-auto" loading={code.isPending} onClick={() => code.mutate()}>
              Send me a PIN
            </Button>
          ) : (
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                sign.mutate();
              }}
            >
              <p className="text-small text-muted">
                PIN sent by {sentTo}.{' '}
                <button
                  type="button"
                  className="text-primary-700 underline"
                  disabled={code.isPending}
                  onClick={() => code.mutate()}
                >
                  Send again
                </button>
              </p>
              <Field
                label="PIN"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                required
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
                error={err('code')}
              />
              <Field
                label="Type your full name"
                hint={`As it appears here: ${you.name}`}
                autoComplete="name"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                error={err('name')}
              />
              <label className="flex items-start gap-2 text-small">
                <input
                  type="checkbox"
                  className="mt-0.5 size-4"
                  checked={form.agree}
                  onChange={(e) => setForm({ ...form, agree: e.target.checked })}
                />
                I have read this agreement and I agree to sign it electronically.
              </label>
              {err('agree') ? <span className="text-small text-danger">{err('agree')}</span> : null}
              <Button type="submit" className="w-full sm:w-auto" loading={sign.isPending} disabled={!form.agree}>
                Sign
              </Button>
            </form>
          )}
          <div className="mt-5 border-t border-line pt-4">
            {declining ? (
              <form
                className="grid gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  decline.mutate();
                }}
              >
                <label className="grid gap-1.5 text-small">
                  <span className="font-medium">Why are you not signing?</span>
                  <textarea
                    required
                    minLength={5}
                    className="min-h-20 rounded-lg border border-line bg-surface p-2"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                </label>
                <div className="flex gap-2">
                  <Button type="submit" variant="danger" size="sm" loading={decline.isPending}>
                    Decline to sign
                  </Button>
                  <Button type="button" variant="secondary" size="sm" onClick={() => setDeclining(false)}>
                    Back
                  </Button>
                </div>
              </form>
            ) : (
              <button type="button" className="text-small text-muted underline" onClick={() => setDeclining(true)}>
                Something is wrong? Decline to sign
              </button>
            )}
          </div>
        </Card>
      )}
      <p className="text-center text-caption text-faint">
        Link works until {when(a.expires_at)} · fingerprint {a.text_sha256.slice(0, 16)}…
      </p>
    </PublicLayout>
  );
}
