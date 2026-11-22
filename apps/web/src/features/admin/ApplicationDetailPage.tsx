import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { ArrowLeft, CheckCircle2, ExternalLink, FileText, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, CardHeader, Dialog, PageHeader, Skeleton, StatusBadge, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import type { ApplicationData } from '@/features/apply/types';

/** Review one application: automatic checks, documents, the reviewer checklist and the decision. */
export function ApplicationDetailPage() {
  const { id } = useParams({ from: '/app/applications/$id' });
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const key = ['admin', 'application', id];
  const q = useQuery({
    queryKey: key,
    queryFn: () => call<{ data: ApplicationData }>('GET', `/admin/applications/${id}`).then((r) => r.data),
  });
  const [notes, setNotes] = useState<string | null>(null);
  const [dialog, setDialog] = useState<'info' | 'decline' | null>(null);

  const done = (a: ApplicationData) => {
    qc.setQueryData(key, a);
    void qc.invalidateQueries({ queryKey: ['admin', 'applications'] });
  };
  const review = useMutation({
    mutationFn: (body: { checklist?: Record<string, boolean>; notes?: string | null }) =>
      call<{ data: ApplicationData }>('PUT', `/admin/applications/${id}/review`, body).then((r) => r.data),
    onSuccess: done,
  });
  const approve = useMutation({
    mutationFn: () => call<{ tenant: { id: string; code: string } }>('POST', `/admin/applications/${id}/approve`),
    onSuccess: (r) => {
      toast(`Approved: ${r.tenant.code} is live and the owner is invited`);
      void qc.invalidateQueries({ queryKey: ['admin'] });
      void navigate({ to: '/tenants/$id', params: { id: r.tenant.id } });
    },
  });

  if (q.isPending) return <Skeleton className="h-96" />;
  if (q.error) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const a = q.data;
  const checklist = a.checklist ?? [];
  const open = a.status === 'submitted';
  const checksOk = Object.values(a.checks).every((c) => c.ok || !c.required);
  const ticked = checklist.every((c) => c.done);

  return (
    <>
      <Link to="/applications" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden /> Applications
      </Link>
      <PageHeader
        eyebrow={a.reference}
        title={a.legal_name}
        description={
          <span className="inline-flex flex-wrap items-center gap-2">
            <StatusBadge status={a.status} /> {a.first_name} {a.last_name} · {a.email}
            {a.phone ? ` · ${a.phone}` : ''}
          </span>
        }
        actions={
          open ? (
            <>
              <Button variant="secondary" onClick={() => setDialog('info')}>
                Ask for information
              </Button>
              <Button variant="danger" onClick={() => setDialog('decline')}>
                Decline
              </Button>
              <Button loading={approve.isPending} disabled={!checksOk || !ticked} onClick={() => approve.mutate()}>
                Approve and create provider
              </Button>
            </>
          ) : null
        }
      />
      {approve.error ? <Alert tone="danger">{errorMessage(approve.error)}</Alert> : null}
      {a.message ? <Alert tone={a.status === 'declined' ? 'danger' : 'warning'}>{a.message}</Alert> : null}

      <div className="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="grid content-start gap-5">
          <Card>
            <CardHeader title="Details" />
            <dl className="grid gap-x-6 gap-y-3 text-small sm:grid-cols-2">
              {(
                [
                  ['Trading name', a.trading_name],
                  ['CIPC number', a.registration_number],
                  ['VAT number', a.vat_number],
                  ['Website', a.website],
                  ['QCTO accreditation', a.sdp_number],
                  ['Valid until', a.accreditation_expires_on],
                  ['Scope (SAQA IDs)', a.scope.join(', ') || null],
                  ['Main centre', a.centre_name],
                  ['Address', [a.address_line1, a.city, a.province, a.postal_code].filter(Boolean).join(', ') || null],
                  ['Web address', a.preferred_code ? `${a.preferred_code}.${a.base_domain}` : null],
                  ['Plan', a.package_id ? `${a.interval === 'year' ? 'Yearly' : 'Monthly'}` : 'Not chosen'],
                  ['Applied from', a.ip],
                ] as const
              ).map(([label, value]) => (
                <div key={label}>
                  <dt className="text-muted">{label}</dt>
                  <dd className="mt-0.5 break-words text-ink">{value ?? '—'}</dd>
                </div>
              ))}
            </dl>
          </Card>
          <Card>
            <CardHeader title="Documents" subtitle="Virus-checked before they can be opened." />
            <ul className="grid gap-2">
              {a.files.map((f) => (
                <li
                  key={f.id}
                  className="flex items-center justify-between gap-3 rounded-lg border border-line p-3 text-small"
                >
                  <span className="flex min-w-0 items-center gap-2">
                    <FileText className="size-4 shrink-0 text-muted" aria-hidden />
                    <span className="truncate">{f.name}</span>
                    <span className="text-muted">{f.kind.replace(/_/g, ' ')}</span>
                  </span>
                  {f.status === 'ready' ? (
                    <a
                      href={`/v1/admin/applications/${a.id}/files/${f.id}`}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 font-medium text-primary-700 hover:underline"
                    >
                      Open <ExternalLink className="size-3.5" aria-hidden />
                    </a>
                  ) : (
                    <StatusBadge status={f.status} />
                  )}
                </li>
              ))}
              {a.files.length === 0 ? <li className="text-small text-muted">No documents.</li> : null}
            </ul>
          </Card>
        </div>

        <div className="grid content-start gap-5">
          <Card>
            <CardHeader title="Automatic checks" />
            <ul className="grid gap-2">
              {Object.entries(a.checks).map(([k, c]) => (
                <li key={k} className="flex items-start gap-2 text-small">
                  {c.ok ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Passed" />
                  ) : (
                    <XCircle
                      className={`mt-0.5 size-4 shrink-0 ${c.required ? 'text-danger' : 'text-warning'}`}
                      aria-label={c.required ? 'Failed' : 'Signal'}
                    />
                  )}
                  <span>
                    {c.message}
                    {!c.required ? <span className="text-muted"> (signal)</span> : null}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
          <Card>
            <CardHeader title="Reviewer checklist" subtitle="Confirm each before approving." />
            <div className="grid gap-2">
              {checklist.map((c) => (
                <label key={c.key} className="flex items-start gap-2 text-small">
                  <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={c.done}
                    disabled={!open || review.isPending}
                    onChange={(e) => review.mutate({ checklist: { [c.key]: e.target.checked } })}
                  />
                  {c.label}
                </label>
              ))}
              <a
                href="https://bizportal.gov.za"
                target="_blank"
                rel="noreferrer"
                className="text-small text-primary-700 hover:underline"
              >
                Search CIPC (BizPortal)
              </a>
            </div>
            <label className="mt-4 grid gap-1.5 text-small">
              <span className="font-medium">Notes (staff only)</span>
              <textarea
                className="min-h-24 rounded-lg border border-line bg-surface p-2"
                value={notes ?? a.review_notes ?? ''}
                disabled={!open}
                onChange={(e) => setNotes(e.target.value)}
                onBlur={() => notes !== null && review.mutate({ notes })}
              />
            </label>
            {review.error ? <p className="mt-2 text-small text-danger">{errorMessage(review.error)}</p> : null}
          </Card>
        </div>
      </div>

      {dialog ? <DecisionDialog id={id} kind={dialog} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}

function DecisionDialog({
  id,
  kind,
  onClose,
  onDone,
}: {
  id: string;
  kind: 'info' | 'decline';
  onClose: () => void;
  onDone: (a: ApplicationData) => void;
}) {
  const [text, setText] = useState('');
  const m = useMutation({
    mutationFn: () =>
      call<{ data: ApplicationData }>(
        'POST',
        `/admin/applications/${id}/${kind === 'info' ? 'request-info' : 'decline'}`,
        kind === 'info' ? { message: text } : { reason: text },
      ).then((r) => r.data),
    onSuccess: (a) => {
      onDone(a);
      onClose();
    },
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={kind === 'info' ? 'Ask for more information' : 'Decline application'}
      description={
        kind === 'info'
          ? 'The applicant gets this by email and can edit and resubmit.'
          : 'The applicant gets this reason by email. This cannot be undone.'
      }
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          m.mutate();
        }}
      >
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">{kind === 'info' ? 'What is needed' : 'Reason'}</span>
          <textarea
            required
            minLength={10}
            maxLength={1000}
            className="min-h-28 rounded-lg border border-line bg-surface p-2"
            value={text}
            onChange={(e) => setText(e.target.value)}
          />
        </label>
        {m.error ? <Alert tone="danger">{errorMessage(m.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant={kind === 'decline' ? 'danger' : 'primary'} loading={m.isPending}>
            {kind === 'info' ? 'Send request' : 'Decline'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
