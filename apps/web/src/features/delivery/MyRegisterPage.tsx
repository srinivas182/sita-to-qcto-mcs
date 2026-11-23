import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, Field, PageHeader, Skeleton } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { monthName, type RegisterRow } from './RegistersPage';
import { STATUS_LABEL, type AttendanceStatus } from './types';

interface Grid {
  sessions: { id: string; date: string; start: string; end: string; kind: string; module: string | null }[];
  learners: {
    user_id: string;
    name: string;
    marks: Record<string, AttendanceStatus | null>;
    held: number;
    attended: number;
    excused: number;
    pct: number | null;
    workplace_days?: number;
    workplace_hours?: number;
    workplace?: Record<string, number>;
  }[];
}
interface Mine {
  id: string;
  role: 'learner' | 'facilitator' | 'mentor';
  my_status: 'pending' | 'signed' | 'disputed';
}

/** A learner checks their month and signs (or disputes); a facilitator signs the whole register; a mentor signs the workplace days. */
export function MyRegisterPage() {
  const { id } = useParams({ from: '/app/my-registers/$id' });
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['register', id],
    queryFn: () => call<{ data: RegisterRow & { grid: Grid } }>('GET', `/registers/${id}`).then((r) => r.data),
  });
  const mine = useQuery({
    queryKey: ['me', 'registers'],
    queryFn: () => call<{ data: Mine[] }>('GET', '/me/registers').then((r) => r.data.filter((x) => x.id === id)),
  });
  const [name, setName] = useState('');
  const [disputing, setDisputing] = useState(false);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const done = () => {
    setErrors({});
    void qc.invalidateQueries({ queryKey: ['register', id] });
    void qc.invalidateQueries({ queryKey: ['me', 'registers'] });
  };
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { name: [errorMessage(e)] });
  const sign = useMutation({
    mutationFn: (role: string) => call('POST', `/registers/${id}/sign`, { name, role }),
    onSuccess: done,
    onError: fail,
  });
  const dispute = useMutation({
    mutationFn: () => call('POST', `/registers/${id}/dispute`, { reason }),
    onSuccess: done,
    onError: fail,
  });

  if (q.isPending || mine.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const r = q.data;
  const me = mine.data?.find((x) => x.role === 'learner') ?? mine.data?.[0];
  const mentor = me?.role === 'mentor';
  const line = !mentor && r.grid.learners.length === 1 ? r.grid.learners[0] : null;

  return (
    <>
      <PageHeader eyebrow={r.cohort ?? ''} title={`Attendance register: ${monthName(r.month)}`} />
      {mentor ? (
        <Card>
          <p className="mb-3 text-body text-ink">Days your learners logged at your workplace this month:</p>
          <ul className="grid gap-3 text-small">
            {r.grid.learners.map((l) => (
              <li key={l.user_id} className="border-b border-line pb-2">
                <p className="font-medium text-ink">
                  {l.name}: {l.workplace_days ?? 0} days, {l.workplace_hours ?? 0} h
                </p>
                <p className="text-muted">
                  {Object.entries(l.workplace ?? {})
                    .map(([d, m]) => `${d.slice(8)} (${Math.round((m / 60) * 10) / 10} h)`)
                    .join(' · ')}
                </p>
              </li>
            ))}
          </ul>
        </Card>
      ) : line ? (
        <Card>
          <p className="mb-3 text-body text-ink">
            You attended <strong>{line.attended}</strong> of {line.held} classes
            {line.excused ? ` (${line.excused} excused)` : ''}
            {line.pct !== null ? `: ${line.pct}%` : ''}.
          </p>
          <ul className="grid gap-1 text-small">
            {r.grid.sessions.map((s) => {
              const m = line.marks[s.id];
              return (
                <li key={s.id} className="flex justify-between gap-2 border-b border-line py-1.5">
                  <span>
                    {s.date} {s.start}–{s.end}{' '}
                    <span className="text-muted">
                      {s.module ?? (s.kind === 'practical' ? 'Practical' : 'Knowledge')}
                    </span>
                  </span>
                  <span className={m === 'absent' ? 'text-danger' : 'text-ink'}>
                    {m ? STATUS_LABEL[m] : 'Not enrolled yet'}
                  </span>
                </li>
              );
            })}
          </ul>
        </Card>
      ) : (
        <Card>
          <p className="text-small text-muted">
            {r.counts.learners} learners · {r.grid.sessions.length} classes. The full register is in the PDF.
          </p>
        </Card>
      )}
      <div className="mt-4">
        {!me ? null : me.my_status !== 'pending' ? (
          <Alert tone={me.my_status === 'signed' ? 'success' : 'warning'}>
            <span className="inline-flex items-center gap-2">
              <CheckCircle2 className="size-4" aria-hidden />
              {me.my_status === 'signed'
                ? 'You signed this register.'
                : 'You disputed this register. The provider will contact you.'}
            </span>
          </Alert>
        ) : r.status === 'submitted' ? (
          <Alert tone="info">This register has already been sent.</Alert>
        ) : (
          <Card>
            {disputing ? (
              <div className="grid gap-3">
                <Field
                  label="What is wrong?"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  error={errors.reason?.[0]}
                />
                <div className="flex gap-2">
                  <Button
                    variant="danger"
                    loading={dispute.isPending}
                    disabled={reason.trim().length < 5}
                    onClick={() => dispute.mutate()}
                  >
                    Send
                  </Button>
                  <Button variant="secondary" onClick={() => setDisputing(false)}>
                    Back
                  </Button>
                </div>
              </div>
            ) : (
              <form
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  sign.mutate(me.role);
                }}
              >
                <p className="text-small text-muted">
                  {me.role === 'facilitator'
                    ? 'By signing you confirm this register is a true record of the classes you facilitated.'
                    : me.role === 'mentor'
                      ? 'By signing you confirm these learners worked at your workplace on these days.'
                      : 'By signing you confirm your attendance above is correct.'}
                </p>
                <Field
                  label="Type your full name"
                  autoComplete="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  error={errors.name?.[0] ?? errors.register?.[0]}
                />
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" loading={sign.isPending} disabled={!name.trim()}>
                    Sign
                  </Button>
                  {me.role === 'learner' ? (
                    <Button type="button" variant="ghost" onClick={() => setDisputing(true)}>
                      Something is wrong
                    </Button>
                  ) : null}
                </div>
              </form>
            )}
          </Card>
        )}
      </div>
    </>
  );
}
