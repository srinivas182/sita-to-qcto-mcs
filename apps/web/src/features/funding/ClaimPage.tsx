import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  Field,
  PageHeader,
  Select,
  Skeleton,
  useToast,
} from '@/components/ui';
import { downloadFrom } from '@/features/delivery/RegistersPage';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';
import { type Claim, type ClaimQuery, claimStatus } from './claims';

const fieldError = (e: unknown) =>
  e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e);
type Action = 'sent' | 'query' | 'paid' | 'rejected' | 'void' | null;

/** One claim: its learners, the pack, the funder's queries and what was fixed. */
export function ClaimPage() {
  const { id } = useParams({ from: '/app/grant-claims/$id' });
  const manage = useCan()('funding.manage');
  const toast = useToast();
  const [action, setAction] = useState<Action>(null);
  const [resolving, setResolving] = useState<ClaimQuery | null>(null);
  const q = useQuery({
    queryKey: ['claim', id],
    queryFn: () => call<{ data: Claim }>('GET', `/grant-claims/${id}`).then((r) => r.data),
  });
  const agreement = useQuery({
    queryKey: ['grant', q.data?.agreement_id],
    enabled: !!q.data,
    queryFn: () =>
      call<{ data: { agreement: { cohort_id: string } } }>('GET', `/grant-agreements/${q.data?.agreement_id}`).then(
        (r) => r.data,
      ),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const c = q.data;
  const cohortId = agreement.data?.agreement.cohort_id;
  const open = c.queries.filter((x) => !x.resolved_at).length;
  const name = (enrolment: string | null) => c.learners.find((l) => l.enrolment_id === enrolment)?.name;
  return (
    <>
      <Link
        to="/grant-agreements/$id"
        params={{ id: c.agreement_id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Agreement
      </Link>
      <PageHeader
        title={`Claim ${c.number}`}
        description={`${c.learners.length} learners · ${money(c.amount_cents)}${c.sent_on ? ` · sent ${shortDate(c.sent_on)}` : ''}${c.paid_on ? ` · paid ${money(c.paid_cents ?? 0)} on ${shortDate(c.paid_on)}` : ''}`}
        actions={
          manage ? (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                icon={<Download className="size-4" />}
                onClick={() =>
                  void downloadFrom(`/grant-claims/${id}/pack`, `${c.number}.zip`).catch((e: unknown) =>
                    toast(errorMessage(e), 'error'),
                  )
                }
              >
                Claim pack
              </Button>
              {c.status === 'draft' || (c.status === 'queried' && open === 0) ? (
                <Button onClick={() => setAction('sent')}>
                  {c.status === 'draft' ? 'Mark sent' : 'Mark sent again'}
                </Button>
              ) : null}
              {c.status === 'sent' || c.status === 'queried' ? (
                <>
                  <Button variant="secondary" onClick={() => setAction('query')}>
                    Record a query
                  </Button>
                  <Button variant="secondary" onClick={() => setAction('paid')}>
                    Mark paid
                  </Button>
                  <Button variant="ghost" onClick={() => setAction('rejected')}>
                    Rejected
                  </Button>
                </>
              ) : null}
              {c.status === 'draft' ? (
                <Button variant="ghost" onClick={() => setAction('void')}>
                  Cancel claim
                </Button>
              ) : null}
            </div>
          ) : null
        }
      />
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Badge tone={claimStatus[c.status].tone}>{claimStatus[c.status].label}</Badge>
        {c.closing_note ? <span className="text-small text-muted">{c.closing_note}</span> : null}
      </div>
      {c.queries.length ? (
        <Card className="mb-4">
          <CardHeader title={`Queries (${open} open)`} />
          <ul className="divide-y divide-line text-small">
            {c.queries.map((x) => (
              <li key={x.id} className="grid gap-1 py-3">
                <p className="text-ink">
                  {x.enrolment_id ? <span className="font-medium">{name(x.enrolment_id)}: </span> : null}
                  {x.problem} <span className="text-muted">({shortDate(x.raised_on)})</span>
                </p>
                {x.resolved_at ? (
                  <p className="text-success">Fixed: {x.resolution}</p>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {x.enrolment_id && cohortId ? (
                      <Link
                        to="/cohorts/$id/results/$enrolment"
                        params={{ id: cohortId, enrolment: x.enrolment_id }}
                        className="font-medium text-primary-700 hover:underline"
                      >
                        Open {name(x.enrolment_id)}&apos;s record
                      </Link>
                    ) : null}
                    {manage ? (
                      <Button size="sm" variant="secondary" onClick={() => setResolving(x)}>
                        Mark fixed<span className="sr-only">: {x.problem}</span>
                      </Button>
                    ) : null}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
      <Card>
        <CardHeader title="Learners on this claim" />
        <ul className="divide-y divide-line text-small">
          {c.learners.map((l) => (
            <li key={l.enrolment_id} className="flex flex-wrap justify-between gap-2 py-2">
              <span className="text-ink">{l.name}</span>
              <span className="text-ink">
                {money(l.amount_cents)}
                {l.disability_cents ? ` + ${money(l.disability_cents)} disability allowance` : ''}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      {action ? <ActionDialog claim={c} action={action} onClose={() => setAction(null)} /> : null}
      {resolving ? <ResolveDialog claimId={c.id} query={resolving} onClose={() => setResolving(null)} /> : null}
    </>
  );
}

const titles: Record<Exclude<Action, null>, string> = {
  sent: 'Mark the claim sent',
  query: 'Record the funder’s query',
  paid: 'Mark the claim paid',
  rejected: 'The funder rejected the claim',
  void: 'Cancel this draft claim',
};

function ActionDialog({
  claim,
  action,
  onClose,
}: {
  claim: Claim;
  action: Exclude<Action, null>;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({
    date: today,
    problem: '',
    enrolment_id: '',
    amount: String(claim.amount_cents / 100),
    note: '',
    reason: '',
  });
  const [proof, setProof] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const body = (): [string, Record<string, unknown>] => {
    switch (action) {
      case 'sent':
        return ['sent', { sent_on: form.date }];
      case 'query':
        return ['queries', { problem: form.problem, enrolment_id: form.enrolment_id || null, raised_on: form.date }];
      case 'paid':
        return ['paid', { paid_on: form.date, amount: form.amount, note: form.note || null, proof_file_id: proof }];
      default:
        return [action, { reason: form.reason }];
    }
  };
  const save = useMutation({
    mutationFn: () => {
      const [path, payload] = body();
      return call('POST', `/grant-claims/${claim.id}/${path}`, payload);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['claim', claim.id] });
      void qc.invalidateQueries({ queryKey: ['claims', claim.agreement_id] });
      onClose();
    },
    onError: (e) => setError(fieldError(e)),
  });
  const upload = async (f: File | undefined) => {
    if (!f) return;
    setBusy(true);
    try {
      setProof((await uploadFile('grant_document', f)).id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={titles[action]}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Back
          </Button>
          <Button loading={save.isPending} disabled={busy} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {action === 'sent' || action === 'query' || action === 'paid' ? (
          <Field
            label={action === 'sent' ? 'Sent on' : action === 'query' ? 'Raised on' : 'Paid on'}
            type="date"
            value={form.date}
            onChange={(e) => setForm({ ...form, date: e.target.value })}
          />
        ) : null}
        {action === 'query' ? (
          <>
            <Select
              label="About"
              value={form.enrolment_id}
              onChange={(e) => setForm({ ...form, enrolment_id: e.target.value })}
            >
              <option value="">The whole claim or a document</option>
              {claim.learners.map((l) => (
                <option key={l.enrolment_id} value={l.enrolment_id}>
                  {l.name}
                </option>
              ))}
            </Select>
            <Field
              label="What the funder asked"
              value={form.problem}
              onChange={(e) => setForm({ ...form, problem: e.target.value })}
            />
          </>
        ) : null}
        {action === 'paid' ? (
          <>
            <Field
              label="Amount received (R)"
              type="number"
              min={0}
              step="0.01"
              value={form.amount}
              onChange={(e) => setForm({ ...form, amount: e.target.value })}
            />
            <Field
              label="Note (needed if the amount differs)"
              value={form.note}
              onChange={(e) => setForm({ ...form, note: e.target.value })}
            />
            <div className="grid gap-1.5">
              <label htmlFor="remit" className="text-small font-medium text-ink">
                Remittance advice (optional){proof ? ': uploaded' : ''}
              </label>
              <input
                id="remit"
                type="file"
                accept="application/pdf,image/jpeg,image/png"
                onChange={(e) => void upload(e.target.files?.[0])}
              />
            </div>
          </>
        ) : null}
        {action === 'rejected' || action === 'void' ? (
          <Field label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
        ) : null}
        {action === 'rejected' ? (
          <p className="text-small text-muted">
            Its learners can be put on a new claim. The invoice number stays used.
          </p>
        ) : null}
      </div>
    </Dialog>
  );
}

function ResolveDialog({ claimId, query, onClose }: { claimId: string; query: ClaimQuery; onClose: () => void }) {
  const qc = useQueryClient();
  const [resolution, setResolution] = useState('');
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => call('POST', `/claim-queries/${query.id}/resolved`, { resolution }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['claim', claimId] });
      onClose();
    },
    onError: (e) => setError(fieldError(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Mark the query fixed"
      description={query.problem}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Back
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label="What was fixed" value={resolution} onChange={(e) => setResolution(e.target.value)} />
      </div>
    </Dialog>
  );
}
