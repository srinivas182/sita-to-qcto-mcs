import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Dialog, Field, PageHeader, Select, Skeleton, useToast } from '@/components/ui';
import { downloadFrom } from '@/features/delivery/RegistersPage';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';
import { type StipendLine, type StipendRun, monthName } from './stipends';

const fieldError = (e: unknown) =>
  e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e);

/** One month's stipends: check, change with a reason, confirm, bank file, mark paid. */
export function StipendRunPage() {
  const { id } = useParams({ from: '/app/stipend-runs/$id' });
  const manage = useCan()('funding.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [changing, setChanging] = useState<StipendLine | null>(null);
  const [paying, setPaying] = useState(false);
  const q = useQuery({
    queryKey: ['stipend-run', id],
    queryFn: () =>
      call<{ data: StipendRun & { lines: StipendLine[] } }>('GET', `/stipend-runs/${id}`).then((r) => r.data),
  });
  const confirm = useMutation({
    mutationFn: () => call('POST', `/stipend-runs/${id}/confirm`),
    onSuccess: () => {
      toast('Stipends confirmed');
      void qc.invalidateQueries({ queryKey: ['stipend-run', id] });
    },
    onError: (e) => toast(fieldError(e), 'error'),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const r = q.data;
  const held = r.lines.filter((l) => l.status === 'hold').length;
  return (
    <>
      <Link
        to="/grant-agreements/$id"
        params={{ id: r.agreement_id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Agreement
      </Link>
      <PageHeader
        title={`Stipends for ${monthName(r.month)}`}
        description={`${money(r.total_cents)} to pay · ${r.lines.length - held} paid, ${held} held${r.paid_on ? ` · paid ${shortDate(r.paid_on)}` : ''}`}
        actions={
          manage ? (
            <div className="flex flex-wrap gap-2">
              {r.status === 'draft' ? (
                <Button loading={confirm.isPending} onClick={() => confirm.mutate()}>
                  Confirm stipends
                </Button>
              ) : (
                <Button
                  variant="secondary"
                  icon={<Download className="size-4" />}
                  onClick={() =>
                    void downloadFrom(`/stipend-runs/${id}/bank-file`, `stipends-${r.month}.csv`).catch((e: unknown) =>
                      toast(errorMessage(e), 'error'),
                    )
                  }
                >
                  Bank file
                </Button>
              )}
              {r.status === 'confirmed' ? <Button onClick={() => setPaying(true)}>Mark paid</Button> : null}
            </div>
          ) : null
        }
      />
      {r.status === 'draft' ? (
        <div className="mb-4">
          <Alert tone="info">
            Check each line. Change an amount only with a reason; it is recorded. After you confirm, the bank file can
            be made.
          </Alert>
        </div>
      ) : null}
      <Card>
        <ul className="divide-y divide-line">
          {r.lines.map((l) => (
            <li key={l.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
              <div className="grid gap-0.5">
                <p className="font-medium text-ink">{l.name}</p>
                <p className="text-small text-muted">
                  Attendance {l.attendance_pct ?? '—'}% ·{' '}
                  {l.bank ? `${l.bank.bank_name} ${l.bank.account}` : 'no bank details'}
                </p>
                {l.override_reason ? (
                  <p className="text-small text-muted">Changed: {l.override_reason}</p>
                ) : l.reason ? (
                  <p className="text-small text-muted">{l.reason}</p>
                ) : null}
              </div>
              <div className="flex items-center gap-3">
                <span className="text-ink">{money(l.amount_cents)}</span>
                <Badge tone={l.status === 'pay' ? 'success' : 'warning'}>{l.status === 'pay' ? 'Pay' : 'Held'}</Badge>
                {manage && r.status === 'draft' ? (
                  <Button size="sm" variant="ghost" onClick={() => setChanging(l)}>
                    Change<span className="sr-only"> stipend for {l.name}</span>
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      </Card>
      {changing ? <LineDialog line={changing} runId={id} onClose={() => setChanging(null)} /> : null}
      {paying ? <PaidDialog runId={id} onClose={() => setPaying(false)} /> : null}
    </>
  );
}

function LineDialog({ line, runId, onClose }: { line: StipendLine; runId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ amount: String(line.amount_cents / 100), status: line.status, reason: '' });
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => call('PATCH', `/stipend-lines/${line.id}`, form),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['stipend-run', runId] });
      onClose();
    },
    onError: (e) => setError(fieldError(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Change stipend: ${line.name ?? 'learner'}`}
      description={`Proposed ${money(line.proposed_cents)}${line.reason ? ` (${line.reason})` : ''}.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Select
          label="Pay or hold"
          value={form.status}
          onChange={(e) => setForm({ ...form, status: e.target.value as 'pay' | 'hold' })}
        >
          <option value="pay">Pay</option>
          <option value="hold">Hold</option>
        </Select>
        {form.status === 'pay' ? (
          <Field
            label="Amount (R)"
            type="number"
            min={0}
            step="0.01"
            value={form.amount}
            onChange={(e) => setForm({ ...form, amount: e.target.value })}
          />
        ) : null}
        <Field
          label="Reason (recorded)"
          value={form.reason}
          onChange={(e) => setForm({ ...form, reason: e.target.value })}
        />
      </div>
    </Dialog>
  );
}

function PaidDialog({ runId, onClose }: { runId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [paidOn, setPaidOn] = useState(new Date().toISOString().slice(0, 10));
  const [proof, setProof] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => call('POST', `/stipend-runs/${runId}/paid`, { paid_on: paidOn, proof_file_id: proof }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['stipend-run', runId] });
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
      title="Mark stipends paid"
      description="Upload the bank's proof of payment. Learners are told their stipend was paid, or why it is held."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={busy || !proof} onClick={() => save.mutate()}>
            Mark paid
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label="Paid on" type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
        <div className="grid gap-1.5">
          <label htmlFor="pop" className="text-small font-medium text-ink">
            Proof of payment{proof ? ': uploaded' : ''}
          </label>
          <input
            id="pop"
            type="file"
            accept="application/pdf,image/jpeg,image/png"
            onChange={(e) => void upload(e.target.files?.[0])}
          />
        </div>
      </div>
    </Dialog>
  );
}
