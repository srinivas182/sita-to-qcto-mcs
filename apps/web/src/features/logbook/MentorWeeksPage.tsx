import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
import { type Entry, duration } from './types';

interface Week {
  id: string;
  week_start: string;
  status: 'open' | 'signed' | 'returned';
  signed_at: string | null;
  comment: string | null;
  learner: string | null;
  entries: number;
  minutes: number;
}

interface Data {
  is_mentor: boolean;
  learners: {
    placement_id: string;
    name: string | null;
    site: string | null;
    starts_on: string;
    ends_on: string | null;
    active: boolean;
  }[];
  weeks: Week[];
}

const weekState = {
  open: { label: 'To sign', tone: 'warning' },
  returned: { label: 'Sent back', tone: 'danger' },
  signed: { label: 'Signed', tone: 'success' },
} as const;

/** For a workplace mentor: the learners placed with them and their logbook weeks to sign. */
export function MentorWeeksPage() {
  const [open, setOpen] = useState<Week | null>(null);
  const q = useQuery({
    queryKey: ['mentoring-weeks'],
    queryFn: () => call<{ data: Data }>('GET', '/mentoring/weeks').then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const d = q.data;
  const toSign = d.weeks.filter((w) => w.status !== 'signed');
  const signed = d.weeks.filter((w) => w.status === 'signed');
  return (
    <>
      <PageHeader
        title="Logbooks to sign"
        description="Check each learner's week on site. Sign it if it is right, or send it back with what to fix."
      />
      {!d.is_mentor ? (
        <Card>
          <EmptyState title="You are not set up as a mentor">
            Ask the training provider to link your account to your mentor record.
          </EmptyState>
        </Card>
      ) : (
        <div className="grid gap-4">
          <Card>
            <CardHeader title="Weeks to sign" />
            {toSign.length === 0 ? (
              <EmptyState title="All signed">New weeks appear when your learners log work.</EmptyState>
            ) : (
              <WeekList weeks={toSign} onOpen={setOpen} />
            )}
          </Card>
          <Card>
            <CardHeader title="Your learners" />
            {d.learners.length === 0 ? (
              <EmptyState title="No learners placed with you">The provider places learners with you.</EmptyState>
            ) : (
              <ul className="divide-y divide-line">
                {d.learners.map((l) => (
                  <li key={l.placement_id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <div>
                      <p className="font-medium text-ink">{l.name}</p>
                      <p className="text-small text-muted">
                        {l.site} · from {shortDate(l.starts_on)}
                        {l.ends_on ? ` to ${shortDate(l.ends_on)}` : ''}
                      </p>
                    </div>
                    {l.active ? <Badge tone="success">On site</Badge> : <Badge>Not on site now</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {signed.length > 0 ? (
            <Card>
              <CardHeader title="Recently signed" />
              <WeekList weeks={signed} onOpen={setOpen} />
            </Card>
          ) : null}
        </div>
      )}
      {open ? <WeekDialog week={open} onClose={() => setOpen(null)} /> : null}
    </>
  );
}

function WeekList({ weeks, onOpen }: { weeks: Week[]; onOpen: (w: Week) => void }) {
  return (
    <ul className="divide-y divide-line">
      {weeks.map((w) => (
        <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
          <div>
            <p className="font-medium text-ink">{w.learner}</p>
            <p className="text-small text-muted">
              Week of {shortDate(w.week_start)} · {w.entries} {w.entries === 1 ? 'entry' : 'entries'} ·{' '}
              {duration(w.minutes)}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Badge tone={weekState[w.status].tone}>{weekState[w.status].label}</Badge>
            <Button size="sm" variant={w.status === 'signed' ? 'ghost' : 'primary'} onClick={() => onOpen(w)}>
              {w.status === 'signed' ? 'View' : 'Check'}
              <span className="sr-only">
                {' '}
                {w.learner}, week of {shortDate(w.week_start)}
              </span>
            </Button>
          </div>
        </li>
      ))}
    </ul>
  );
}

type Detail = Week & { entries: (Entry & { module: string | null; activity: string })[] };

function WeekDialog({ week, onClose }: { week: Week; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [confirm, setConfirm] = useState(false);
  const [returning, setReturning] = useState(false);
  const [comment, setComment] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['logbook-week', week.id],
    queryFn: () => call<{ data: Detail }>('GET', `/logbook-weeks/${week.id}`).then((r) => r.data),
  });
  const done = (message: string) => {
    toast(message);
    void qc.invalidateQueries({ queryKey: ['mentoring-weeks'] });
    onClose();
  };
  const fail = (e: unknown) =>
    setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e));
  const sign = useMutation({
    mutationFn: () => call('POST', `/logbook-weeks/${week.id}/sign`, { confirm }),
    onSuccess: () => done('Week signed; the learner is told'),
    onError: fail,
  });
  const send = useMutation({
    mutationFn: () => call('POST', `/logbook-weeks/${week.id}/return`, { comment, entry_ids: picked }),
    onSuccess: () => done('Sent back to the learner'),
    onError: fail,
  });
  const editable = q.data !== undefined && q.data.status !== 'signed';

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`${week.learner ?? 'Learner'}, week of ${shortDate(week.week_start)}`}
      footer={
        editable ? (
          returning ? (
            <>
              <Button variant="secondary" onClick={() => setReturning(false)}>
                Back
              </Button>
              <Button variant="danger" loading={send.isPending} onClick={() => send.mutate()}>
                Send back
              </Button>
            </>
          ) : (
            <>
              <Button variant="secondary" onClick={() => setReturning(true)}>
                Send back…
              </Button>
              <Button loading={sign.isPending} disabled={!confirm} onClick={() => sign.mutate()}>
                Sign the week
              </Button>
            </>
          )
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
          {q.data.comment && q.data.status === 'returned' ? (
            <Alert tone="warning">You wrote: {q.data.comment}</Alert>
          ) : null}
          <ul className="divide-y divide-line">
            {q.data.entries.map((e) => (
              <li key={e.id} className="flex gap-3 py-2.5">
                {returning ? (
                  <input
                    type="checkbox"
                    className="mt-1 size-5"
                    aria-label={`Send back ${shortDate(e.work_date)}, ${e.activity}`}
                    checked={picked.includes(e.id)}
                    onChange={(ev) =>
                      setPicked(ev.target.checked ? [...picked, e.id] : picked.filter((x) => x !== e.id))
                    }
                  />
                ) : null}
                <div className="min-w-0">
                  <p className="font-medium text-ink">
                    {shortDate(e.work_date)} · {e.activity} · {duration(e.minutes)}
                  </p>
                  {e.note ? <p className="text-small text-ink">{e.note}</p> : null}
                  <p className="text-small text-muted">
                    {e.module}
                    {e.distance_m !== null
                      ? e.location_ok
                        ? ' · on site'
                        : ` · ${e.distance_m} m from site`
                      : ' · no location'}
                    {e.status === 'returned' ? ' · sent back, not fixed yet' : ''}
                  </p>
                </div>
              </li>
            ))}
          </ul>
          {editable && returning ? (
            <div className="grid gap-1.5">
              <label htmlFor="return-comment" className="text-small font-medium text-ink">
                What should the learner fix?
              </label>
              <textarea
                id="return-comment"
                className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
              />
              <p className="text-small text-muted">Tick the entries to fix, or none to send the whole week back.</p>
            </div>
          ) : editable ? (
            <label className="flex items-start gap-2 text-small">
              <input
                type="checkbox"
                className="mt-0.5 size-5"
                checked={confirm}
                onChange={(e) => setConfirm(e.target.checked)}
              />
              I confirm the learner did this work at our workplace, as written.
            </label>
          ) : (
            <Alert tone="success">Signed {q.data.signed_at ? shortDate(q.data.signed_at) : ''}.</Alert>
          )}
        </div>
      )}
    </Dialog>
  );
}
