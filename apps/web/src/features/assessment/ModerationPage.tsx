import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  EmptyState,
  PageHeader,
  Skeleton,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { type Instrument, type ResultRow, photoLinks } from './types';

interface Described {
  learner?: string | null;
  assessor?: string | null;
  module?: string | null;
  instrument?: string | null;
  outcome?: 'competent' | 'nyc';
  attempt?: number;
  assessed_on?: string;
  cohort?: string | null;
}

interface SampleRow extends Described {
  id: string;
  result_id: string;
  cohort_id: string;
  module_id: string;
  reason: 'nyc' | 'new_assessor' | 'facilitator' | 'random';
  status: 'open' | 'done';
  decision: 'agree' | 'overturn' | null;
}

interface AppealRow extends Described {
  id: string;
  result_id: string;
  reason: string;
  lodged_at: string;
  decide_by: string;
  status: 'open' | 'upheld' | 'overturned';
  decision_note: string | null;
  overdue: boolean;
}

export const reasonLabel = {
  nyc: 'Not yet competent',
  new_assessor: 'New assessor',
  facilitator: 'Own facilitator',
  random: 'Random',
} as const;

const outcomeLabel = (o?: string) => (o === 'competent' ? 'Competent' : 'Not yet competent');

/** The moderator's queue: sampled decisions to moderate, and appeals to decide. */
export function ModerationPage() {
  const [sample, setSample] = useState<SampleRow | null>(null);
  const [appeal, setAppeal] = useState<AppealRow | null>(null);
  const q = useQuery({
    queryKey: ['moderation'],
    queryFn: () =>
      call<{ data: { samples: SampleRow[]; appeals: AppealRow[] } }>('GET', '/me/moderation').then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const open = q.data.samples.filter((s) => s.status === 'open');
  const done = q.data.samples.filter((s) => s.status === 'done');
  const cohorts = [...new Map(q.data.samples.map((s) => [s.cohort_id, s.cohort])).entries()];
  return (
    <>
      <PageHeader
        title="Moderation"
        description="Decisions sampled by your provider's policy, and learners' appeals. You can't moderate your own assessments."
      />
      <div className="grid gap-4">
        <Card>
          <CardHeader title="Appeals" />
          {q.data.appeals.length === 0 ? (
            <EmptyState title="No appeals">Appeals from learners appear here.</EmptyState>
          ) : (
            <ul className="divide-y divide-line">
              {q.data.appeals.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <div>
                    <p className="font-medium text-ink">
                      {a.learner} · {a.module} · {a.instrument}
                    </p>
                    <p className="text-small text-muted">
                      {outcomeLabel(a.outcome)} by {a.assessor} · decide by {shortDate(a.decide_by)}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {a.overdue ? <Badge tone="danger">Overdue</Badge> : null}
                    {a.status === 'open' ? (
                      <Button size="sm" onClick={() => setAppeal(a)}>
                        Decide<span className="sr-only"> appeal from {a.learner}</span>
                      </Button>
                    ) : (
                      <Badge tone={a.status === 'overturned' ? 'warning' : 'neutral'}>
                        {a.status === 'overturned' ? 'Overturned' : 'Upheld'}
                      </Badge>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card>
          <CardHeader title={`To moderate (${open.length})`} />
          {open.length === 0 ? (
            <EmptyState title="Nothing waiting">Sampled decisions appear here as assessors record them.</EmptyState>
          ) : (
            <SampleList rows={open} onOpen={setSample} />
          )}
        </Card>
        {done.length ? (
          <Card>
            <CardHeader title="Moderated in the last 30 days" />
            <SampleList rows={done} onOpen={setSample} />
          </Card>
        ) : null}
        {cohorts.length ? (
          <Card>
            <CardHeader title="Reports" />
            <ul className="divide-y divide-line">
              {cohorts.map(([id, name]) => (
                <li key={id} className="py-2">
                  <Link
                    to="/cohorts/$id/moderation"
                    params={{ id }}
                    className="font-medium text-primary-700 hover:underline"
                  >
                    {name}: moderation and reports
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
        ) : null}
      </div>
      {sample ? <SampleDialog row={sample} onClose={() => setSample(null)} /> : null}
      {appeal ? <AppealDialog row={appeal} onClose={() => setAppeal(null)} /> : null}
    </>
  );
}

function SampleList({ rows, onOpen }: { rows: SampleRow[]; onOpen: (s: SampleRow) => void }) {
  return (
    <ul className="divide-y divide-line">
      {rows.map((s) => (
        <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
          <div>
            <p className="font-medium text-ink">
              {s.learner} · {s.module} · {s.instrument}
            </p>
            <p className="text-small text-muted">
              {outcomeLabel(s.outcome)} by {s.assessor}, attempt {s.attempt},{' '}
              {s.assessed_on ? shortDate(s.assessed_on) : ''} · {s.cohort}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Badge>{reasonLabel[s.reason]}</Badge>
            {s.decision ? (
              <Badge tone={s.decision === 'agree' ? 'success' : 'warning'}>
                {s.decision === 'agree' ? 'Agreed' : 'Overturned'}
              </Badge>
            ) : null}
            <Button size="sm" variant={s.status === 'open' ? 'primary' : 'ghost'} onClick={() => onOpen(s)}>
              {s.status === 'open' ? 'Moderate' : 'View'}
              <span className="sr-only">
                {' '}
                {s.learner}, {s.instrument}
              </span>
            </Button>
          </div>
        </li>
      ))}
    </ul>
  );
}

interface SampleDetail {
  id: string;
  status: 'open' | 'done';
  decision: string | null;
  comment: string | null;
  result: ResultRow & { learner: string | null; assessor: string | null };
  instrument: Instrument;
}

function SampleDialog({ row, onClose }: { row: SampleRow; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [comment, setComment] = useState('');
  const [error, setError] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['moderation-sample', row.id],
    queryFn: () => call<{ data: SampleDetail }>('GET', `/moderation-samples/${row.id}`).then((r) => r.data),
  });
  const decide = useMutation({
    mutationFn: (decision: 'agree' | 'overturn') =>
      call('POST', `/moderation-samples/${row.id}/decide`, { decision, comment: comment || null }),
    onSuccess: (_, d) => {
      toast(d === 'agree' ? 'Agreed: the result is final' : 'Overturned: the learner is told');
      void qc.invalidateQueries({ queryKey: ['moderation'] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const open = q.data?.status === 'open';
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`${row.learner ?? 'Learner'}: ${row.instrument ?? ''}`}
      description={`${outcomeLabel(row.outcome)} by ${row.assessor ?? ''}. Sampled: ${reasonLabel[row.reason]}.`}
      footer={
        open ? (
          <>
            <Button
              variant="secondary"
              loading={decide.isPending && decide.variables === 'overturn'}
              onClick={() => decide.mutate('overturn')}
            >
              Overturn
            </Button>
            <Button loading={decide.isPending && decide.variables === 'agree'} onClick={() => decide.mutate('agree')}>
              Agree
            </Button>
          </>
        ) : undefined
      }
    >
      {q.isPending ? (
        <Skeleton className="h-32" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : (
        <div className="grid gap-3">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {q.data.result.intact === false ? (
            <Alert tone="danger">This result changed after the assessor decided it.</Alert>
          ) : null}
          {q.data.result.score !== null ? (
            <p className="text-small text-ink">
              Score {q.data.result.score}% (pass mark {q.data.instrument.pass_mark}%)
            </p>
          ) : null}
          <ul className="grid gap-1.5">
            {q.data.instrument.criteria.map((c) => {
              const r = q.data.result.criteria.find((x) => x.key === c.key);
              return (
                <li key={c.key} className="text-small">
                  <span className={r?.met ? 'text-success' : 'text-danger'}>{r?.met ? 'Met' : 'Not met'}</span> ·{' '}
                  {c.text}
                  {r?.comment ? <span className="block text-muted">{r.comment}</span> : null}
                </li>
              );
            })}
          </ul>
          {q.data.result.feedback ? <p className="text-small text-ink">Feedback: {q.data.result.feedback}</p> : null}
          {photoLinks(q.data.result.file_ids, (m) => toast(m, 'error'))}
          {open ? (
            <div className="grid gap-1.5">
              <label htmlFor="mod-comment" className="text-small font-medium text-ink">
                Comment (needed to overturn)
              </label>
              <textarea
                id="mod-comment"
                className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
              />
            </div>
          ) : (
            <Alert tone={q.data.decision === 'agree' ? 'success' : 'warning'}>
              {q.data.decision === 'agree' ? 'Agreed.' : `Overturned: ${q.data.comment ?? ''}`}
            </Alert>
          )}
        </div>
      )}
    </Dialog>
  );
}

function AppealDialog({ row, onClose }: { row: AppealRow; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const decide = useMutation({
    mutationFn: (outcome: 'upheld' | 'overturned') => call('POST', `/appeals/${row.id}/decide`, { outcome, note }),
    onSuccess: () => {
      toast('Appeal decided; the learner is told');
      void qc.invalidateQueries({ queryKey: ['moderation'] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Appeal from ${row.learner ?? 'a learner'}`}
      description={`${row.module ?? ''} · ${row.instrument ?? ''}: ${outcomeLabel(row.outcome)} by ${row.assessor ?? ''}. Decide by ${shortDate(row.decide_by)}.`}
      footer={
        <>
          <Button
            variant="secondary"
            loading={decide.isPending && decide.variables === 'upheld'}
            onClick={() => decide.mutate('upheld')}
          >
            Result stands
          </Button>
          <Button
            loading={decide.isPending && decide.variables === 'overturned'}
            onClick={() => decide.mutate('overturned')}
          >
            Overturn the result
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <p className="text-small text-ink">“{row.reason}”</p>
        <div className="grid gap-1.5">
          <label htmlFor="appeal-note" className="text-small font-medium text-ink">
            Your reasons (sent to the learner)
          </label>
          <textarea
            id="appeal-note"
            className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </div>
      </div>
    </Dialog>
  );
}
