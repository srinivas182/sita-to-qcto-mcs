import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Dialog, PageHeader, Skeleton, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { AgreementText } from './AgreementText';
import { agreementState, ROLE_LABEL, signerState, when, type AgreementDetail } from './types';

const CHANNEL: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'email' };

/** One agreement: the text everyone signs, each signature and its evidence, and the signed copy. */
export function AgreementPage() {
  const { id } = useParams({ from: '/app/agreements/$id' });
  const can = useCan();
  const qc = useQueryClient();
  const toast = useToast();
  const [cancelling, setCancelling] = useState(false);
  const q = useQuery({
    queryKey: ['agreements', 'detail', id],
    queryFn: () => call<{ data: AgreementDetail }>('GET', `/agreements/${id}`),
  });
  const resend = useMutation({
    mutationFn: (signer: string) => call('POST', `/agreements/${id}/signers/${signer}/resend`),
    onSuccess: () => {
      toast('Sent a new link');
      void qc.invalidateQueries({ queryKey: ['agreements'] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const a = q.data.data;
  const manage = can('agreements.manage');

  return (
    <>
      <Link to="/agreements" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Agreements
      </Link>
      <PageHeader
        eyebrow={a.template ? `${a.template.name} · version ${a.template.version}` : 'Agreement'}
        title={a.title}
        description={`Sent ${when(a.sent_at)}${a.status === 'sent' && a.expires_at ? ` · link works until ${when(a.expires_at)}` : ''}${a.completed_at ? ` · signed by everyone ${when(a.completed_at)}` : ''}`}
        actions={
          <div className="flex flex-wrap gap-2">
            <Badge tone={agreementState[a.status].tone} dot>
              {agreementState[a.status].label}
            </Badge>
            {a.has_document ? (
              <a
                href={`/v1/agreements/${a.id}/document`}
                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-3 text-small font-medium text-white hover:bg-primary-700"
              >
                <Download className="size-4" /> Signed copy
              </a>
            ) : null}
            {manage && a.status === 'sent' ? (
              <Button variant="secondary" onClick={() => setCancelling(true)}>
                Cancel
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <Card>
          <AgreementText text={a.body} />
        </Card>
        <div className="grid content-start gap-4">
          <Card>
            <CardHeader
              title="Signatures"
              subtitle={
                a.signature_level === 'advanced'
                  ? 'Advanced e-signature through LAWtrust.'
                  : 'Each confirmed with a one-time PIN.'
              }
            />
            <ul className="grid gap-3">
              {a.signers.map((s) => (
                <li key={s.id} className="grid gap-1 rounded-lg border border-line p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="font-medium text-ink">{s.name}</div>
                      <div className="text-small text-muted">
                        {ROLE_LABEL[s.role]}
                        {s.contact ? ` · ${s.contact}` : ''}
                      </div>
                    </div>
                    <Badge tone={signerState[s.status]?.tone ?? 'neutral'} dot>
                      {signerState[s.status]?.label ?? s.status}
                    </Badge>
                  </div>
                  {s.status === 'signed' ? (
                    <p className="text-small text-muted">
                      {when(s.signed_at)}
                      {s.evidence?.channel ? ` · PIN by ${CHANNEL[s.evidence.channel] ?? s.evidence.channel}` : ''}
                    </p>
                  ) : null}
                  {s.decline_reason ? <p className="text-small text-danger-700">“{s.decline_reason}”</p> : null}
                  {manage && a.status === 'sent' && s.status === 'pending' && a.signature_level === 'simple' ? (
                    <div>
                      <Button
                        size="sm"
                        variant="secondary"
                        loading={resend.isPending && resend.variables === s.id}
                        onClick={() => resend.mutate(s.id)}
                      >
                        Send a new link<span className="sr-only"> to {s.name}</span>
                      </Button>
                      {s.reminders_sent ? (
                        <span className="ml-2 text-small text-muted">{s.reminders_sent} reminder(s) sent</span>
                      ) : null}
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
          </Card>
          <Card>
            <CardHeader title="Checking it" subtitle="Anyone with the signed copy can check it here." />
            <a
              href={a.verify_url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-1 text-small text-primary-700 hover:underline"
            >
              {a.verify_url} <ExternalLink className="size-3.5" />
            </a>
            <dl className="mt-3 grid gap-2 text-small">
              <div>
                <dt className="text-muted">Text fingerprint (SHA-256)</dt>
                <dd className="font-mono text-[12px] break-all">{a.text_sha256}</dd>
              </div>
              {a.document_sha256 ? (
                <div>
                  <dt className="text-muted">Signed PDF fingerprint</dt>
                  <dd className="font-mono text-[12px] break-all">{a.document_sha256}</dd>
                </div>
              ) : null}
            </dl>
          </Card>
        </div>
      </div>
      {cancelling ? <CancelDialog id={a.id} onClose={() => setCancelling(false)} /> : null}
    </>
  );
}

function CancelDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const m = useMutation({
    mutationFn: () => call('POST', `/agreements/${id}/cancel`, { reason }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['agreements'] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Cancel this agreement"
      description="The signing links stop working. Signatures already made are kept in the record."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          m.mutate();
        }}
      >
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Reason</span>
          <textarea
            required
            minLength={3}
            className="min-h-20 rounded-lg border border-line bg-surface p-2"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        {m.error ? <Alert tone="danger">{errorMessage(m.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Keep it
          </Button>
          <Button type="submit" variant="danger" loading={m.isPending}>
            Cancel agreement
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
