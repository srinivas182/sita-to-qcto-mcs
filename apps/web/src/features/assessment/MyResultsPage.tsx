import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Dialog, EmptyState, PageHeader, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { downloadFrom } from '@/features/delivery/RegistersPage';
import type { Statement } from './ReadinessPage';
import { type ModuleResults, type ResultRow, methodLabel, stateBadge } from './types';

/** The learner's results per module, with feedback and what to work on. */
export function MyResultsPage() {
  const [appealing, setAppealing] = useState<{ result: ResultRow; title: string } | null>(null);
  const toast = useToast();
  const sors = useQuery({
    queryKey: ['my-sors'],
    queryFn: () => call<{ data: Statement[] }>('GET', '/me/statements-of-results').then((r) => r.data),
  });
  const q = useQuery({
    queryKey: ['my-results'],
    queryFn: () =>
      call<{ data: { enrolment_id: string; cohort: string | null; modules: ModuleResults[] }[] }>(
        'GET',
        '/me/results',
      ).then((r) => r.data),
  });
  return (
    <>
      <PageHeader
        title="My results"
        description="Your summative results per module. If you are not yet competent, read the feedback before your next attempt."
      />
      <EisaCard />
      {sors.data
        ?.filter((x) => x.status === 'issued')
        .map((x) => (
          <Card key={x.id} className="mb-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p className="font-medium text-ink">Statement of Results {x.number}</p>
                <p className="text-small text-muted">
                  Issued {shortDate(x.issued_at)}. You can now be registered for the external assessment.
                </p>
              </div>
              <Button
                size="sm"
                onClick={() =>
                  void downloadFrom(`/statements-of-results/${x.id}/pdf`, `${x.number}.pdf`).catch((e: unknown) =>
                    toast(errorMessage(e), 'error'),
                  )
                }
              >
                Download<span className="sr-only"> {x.number}</span>
              </Button>
            </div>
          </Card>
        ))}
      {q.isPending ? (
        <Skeleton className="h-40" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No courses">Results appear once you are enrolled.</EmptyState>
        </Card>
      ) : (
        q.data.map((c) => (
          <Card key={c.enrolment_id} className="mb-4">
            <h2 className="mb-2 text-[15px] font-semibold text-ink">{c.cohort}</h2>
            <ul className="divide-y divide-line">
              {c.modules.map((m) => (
                <li key={m.module_id} className="grid gap-1 py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-medium text-ink">
                      {m.code} {m.title}
                    </p>
                    <Badge tone={stateBadge[m.state].tone}>{stateBadge[m.state].label}</Badge>
                  </div>
                  {m.instruments
                    .filter((i) => i.latest)
                    .map((i) => (
                      <div key={i.instrument_id} className="text-small">
                        <p className="text-muted">
                          {i.title} ({methodLabel[i.method]}) · attempt {i.latest!.attempt} ·{' '}
                          {shortDate(i.latest!.assessed_on)}
                          {i.latest!.score !== null ? ` · ${i.latest!.score}%` : ''}
                        </p>
                        {i.latest!.feedback ? <p className="text-ink">{i.latest!.feedback}</p> : null}
                        {i.latest!.remediation ? <p className="text-ink">To work on: {i.latest!.remediation}</p> : null}
                        {i.latest!.appeal ? (
                          <p className="text-muted">
                            Appeal:{' '}
                            {i.latest!.appeal.status === 'open'
                              ? `waiting for a decision by ${shortDate(i.latest!.appeal.decide_by)}`
                              : `${i.latest!.appeal.status === 'overturned' ? 'result changed' : 'result stands'}. ${i.latest!.appeal.decision_note ?? ''}`}
                          </p>
                        ) : i.latest!.can_appeal ? (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setAppealing({ result: i.latest!, title: i.title })}
                          >
                            Appeal<span className="sr-only"> {i.title}</span>
                          </Button>
                        ) : null}
                      </div>
                    ))}
                </li>
              ))}
            </ul>
          </Card>
        ))
      )}
      {appealing ? <AppealDialog {...appealing} onClose={() => setAppealing(null)} /> : null}
    </>
  );
}

function AppealDialog({ result, title, onClose }: { result: ResultRow; title: string; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const send = useMutation({
    mutationFn: () => call('POST', `/assessment-results/${result.id}/appeal`, { reason }),
    onSuccess: () => {
      toast('Appeal sent. A moderator will decide it.');
      void qc.invalidateQueries({ queryKey: ['my-results'] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Appeal: ${title}`}
      description={
        result.appeal_until
          ? `You can appeal until ${shortDate(result.appeal_until)}. A moderator who did not assess you decides.`
          : undefined
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={send.isPending} onClick={() => send.mutate()}>
            Send appeal
          </Button>
        </>
      }
    >
      <div className="grid gap-1.5">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <label htmlFor="appeal-reason" className="text-small font-medium text-ink">
          Why do you think the result is wrong?
        </label>
        <textarea
          id="appeal-reason"
          className="min-h-24 rounded-lg border border-line bg-surface px-3 py-2 text-body"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </div>
    </Dialog>
  );
}

interface MyEisa {
  data: {
    id: string;
    eisa_on: string;
    assessment_centre: string | null;
    assessment_centre_name: string | null;
    declared_at: string | null;
    status: string;
  }[];
  declaration: string;
  name: string;
}

/** The learner's EISA registration and the declaration to sign. */
function EisaCard() {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: ['my-eisa'], queryFn: () => call<MyEisa>('GET', '/me/eisa') });
  const [confirm, setConfirm] = useState(false);
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const sign = useMutation({
    mutationFn: (id: string) => call('POST', `/me/eisa/${id}/declare`, { confirm, name }),
    onSuccess: () => {
      toast('Declaration signed');
      void qc.invalidateQueries({ queryKey: ['my-eisa'] });
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  if (!q.data?.data.length) return null;
  return (
    <>
      {q.data.data.map((r) => (
        <Card key={r.id} className="mb-4">
          <p className="font-medium text-ink">External assessment (EISA) on {shortDate(r.eisa_on)}</p>
          <p className="text-small text-muted">
            {r.assessment_centre_name ?? r.assessment_centre ?? 'Assessment centre to be confirmed'} ·{' '}
            {r.status === 'registered' ? 'You are registered.' : 'Your provider is registering you.'}
          </p>
          {r.declared_at ? (
            <p className="mt-2 text-small text-success">You signed the declaration on {shortDate(r.declared_at)}.</p>
          ) : r.status === 'draft' ? (
            <div className="mt-3 grid gap-2">
              {error ? <Alert tone="danger">{error}</Alert> : null}
              <label className="flex items-start gap-2 text-small">
                <input
                  type="checkbox"
                  className="mt-0.5 size-5"
                  checked={confirm}
                  onChange={(e) => setConfirm(e.target.checked)}
                />
                {q.data.declaration}
              </label>
              <div className="grid gap-1.5">
                <label htmlFor={`sign-${r.id}`} className="text-small font-medium text-ink">
                  Type your full name to sign ({q.data.name})
                </label>
                <input
                  id={`sign-${r.id}`}
                  className="h-10 rounded-lg border border-line bg-surface px-3 text-body"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>
              <div>
                <Button loading={sign.isPending} disabled={!confirm || !name.trim()} onClick={() => sign.mutate(r.id)}>
                  Sign the declaration
                </Button>
              </div>
            </div>
          ) : null}
        </Card>
      ))}
    </>
  );
}
