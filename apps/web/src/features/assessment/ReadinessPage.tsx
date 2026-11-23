import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Dialog, EmptyState, PageHeader, Skeleton, useToast } from '@/components/ui';
import { downloadFrom } from '@/features/delivery/RegistersPage';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';

export interface Statement {
  id: string;
  enrolment_id: string;
  number: string;
  status: 'issued' | 'withdrawn';
  issued_at: string;
  withdrawn_at: string | null;
  withdrawn_reason: string | null;
  verify_code: string;
}

interface Row {
  enrolment_id: string;
  name: string | null;
  ready: boolean;
  blockers: string[];
  statements: Statement[];
}

/** Who is ready for the Statement of Results, what holds the others back, and issuing it. */
export function ReadinessPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/statements' });
  const can = useCan();
  const toast = useToast();
  const [issuing, setIssuing] = useState<Row | null>(null);
  const [withdrawing, setWithdrawing] = useState<Statement | null>(null);
  const q = useQuery({
    queryKey: ['readiness', id],
    queryFn: () => call<{ data: Row[] }>('GET', `/cohorts/${id}/statement-readiness`).then((r) => r.data),
  });
  const ready = q.data?.filter((r) => r.ready && !r.statements.some((s) => s.status === 'issued')).length ?? 0;
  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Cohort
      </Link>
      <PageHeader
        title="Statements of Results"
        description="A learner is ready once every module is Competent and final (moderated if sampled), no appeal is open, and each work experience logbook is checked."
      />
      {q.isPending ? (
        <Skeleton className="h-48" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No learners">Learners appear once enrolled.</EmptyState>
        </Card>
      ) : (
        <Card>
          <p className="mb-2 text-small text-muted">{ready} ready to issue.</p>
          <ul className="divide-y divide-line">
            {q.data.map((r) => {
              const current = r.statements.find((s) => s.status === 'issued');
              return (
                <li key={r.enrolment_id} className="grid gap-1.5 py-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-medium text-ink">{r.name}</p>
                    {current ? (
                      <Badge tone="success">Issued {current.number}</Badge>
                    ) : r.ready ? (
                      <Badge tone="success">Ready</Badge>
                    ) : (
                      <Badge tone="warning">Not ready</Badge>
                    )}
                  </div>
                  {!r.ready && !current ? (
                    <ul className="list-disc pl-5 text-small text-muted">
                      {r.blockers.map((b) => (
                        <li key={b}>{b}</li>
                      ))}
                    </ul>
                  ) : null}
                  <div className="flex flex-wrap gap-2">
                    {r.statements.map((s) => (
                      <Button
                        key={s.id}
                        size="sm"
                        variant="ghost"
                        icon={<Download className="size-4" />}
                        onClick={() =>
                          void downloadFrom(`/statements-of-results/${s.id}/pdf`, `${s.number}.pdf`).catch(
                            (e: unknown) => toast(errorMessage(e), 'error'),
                          )
                        }
                      >
                        {s.number}
                        {s.status === 'withdrawn' ? ' (withdrawn)' : ''}
                      </Button>
                    ))}
                    {can('cohorts.manage') && current ? (
                      <Button size="sm" variant="secondary" onClick={() => setWithdrawing(current)}>
                        Withdraw<span className="sr-only"> {current.number}</span>
                      </Button>
                    ) : null}
                    {can('cohorts.manage') && r.ready && !current ? (
                      <Button size="sm" onClick={() => setIssuing(r)}>
                        Issue<span className="sr-only"> for {r.name}</span>
                      </Button>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ul>
        </Card>
      )}
      {issuing ? <IssueDialog cohortId={id} row={issuing} onClose={() => setIssuing(null)} /> : null}
      {withdrawing ? <WithdrawDialog cohortId={id} s={withdrawing} onClose={() => setWithdrawing(null)} /> : null}
    </>
  );
}

function IssueDialog({ cohortId, row, onClose }: { cohortId: string; row: Row; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [confirm, setConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const issue = useMutation({
    mutationFn: () =>
      call<{ data: Statement }>('POST', `/enrolments/${row.enrolment_id}/statement-of-results`, { confirm }),
    onSuccess: (r) => {
      toast(`Issued ${r.data.number}`);
      void qc.invalidateQueries({ queryKey: ['readiness', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Issue the Statement of Results for ${row.name ?? 'the learner'}`}
      description="It records every module's final result and the work experience, gets a number and a public check link, and fills the Statement of Results columns of the QCTO file."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={issue.isPending} disabled={!confirm} onClick={() => issue.mutate()}>
            Issue
          </Button>
        </>
      }
    >
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <label className="flex items-start gap-2 text-small">
        <input
          type="checkbox"
          className="mt-0.5 size-5"
          checked={confirm}
          onChange={(e) => setConfirm(e.target.checked)}
        />
        I confirm the results are final and correct.
      </label>
    </Dialog>
  );
}

function WithdrawDialog({ cohortId, s, onClose }: { cohortId: string; s: Statement; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const withdraw = useMutation({
    mutationFn: () => call('POST', `/statements-of-results/${s.id}/withdraw`, { reason }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['readiness', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Withdraw ${s.number}`}
      description="Its check page will show it as withdrawn. You can then issue a corrected one."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="danger" loading={withdraw.isPending} onClick={() => withdraw.mutate()}>
            Withdraw
          </Button>
        </>
      }
    >
      <div className="grid gap-1.5">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <label htmlFor="withdraw-reason" className="text-small font-medium text-ink">
          Reason
        </label>
        <textarea
          id="withdraw-reason"
          className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </div>
      {shortDate(s.issued_at) ? <p className="mt-2 text-small text-muted">Issued {shortDate(s.issued_at)}.</p> : null}
    </Dialog>
  );
}
