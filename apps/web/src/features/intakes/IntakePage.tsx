import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Copy, Download, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  PageHeader,
  Skeleton,
  Table,
  cx,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { IntakeDialog } from './IntakesPage';
import { appState, day, intakeState, type AppStatus, type ApplicationRow, type Intake } from './types';

const tabs: Array<[AppStatus | '', string]> = [
  ['', 'All'],
  ['submitted', 'New'],
  ['shortlisted', 'Shortlisted'],
  ['waitlisted', 'Waiting list'],
  ['declined', 'Not successful'],
];

/** One intake: how to share it, and its applications board. */
export function IntakePage() {
  const { id } = useParams({ from: '/app/intakes/$id' });
  const manage = useCan()('applications.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [status, setStatus] = useState<AppStatus | ''>('');
  const [editing, setEditing] = useState(false);
  const [declining, setDeclining] = useState<ApplicationRow | null>(null);
  const intake = useQuery({
    queryKey: ['intakes', id],
    queryFn: () => call<{ data: Intake }>('GET', `/intakes/${id}`).then((r) => r.data),
  });
  const board = useQuery({
    queryKey: ['applications', id],
    queryFn: () =>
      call<{ data: ApplicationRow[]; places: { total: number; taken: number } }>('GET', '/applications', undefined, {
        intake_id: id,
      }),
  });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['applications', id] });
    void qc.invalidateQueries({ queryKey: ['intakes'] });
  };
  const decide = useMutation({
    mutationFn: ({ app, action, reason }: { app: string; action: string; reason?: string }) =>
      call('POST', `/applications/${app}/${action}`, reason ? { reason } : {}),
    onSuccess: () => {
      setDeclining(null);
      refresh();
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const state = useMutation({
    mutationFn: (action: 'publish' | 'close') => call<{ data: Intake }>('POST', `/intakes/${id}/${action}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['intakes'] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (intake.isPending) return <Skeleton className="h-64" />;
  if (intake.isError) return <Alert tone="danger">{errorMessage(intake.error)}</Alert>;
  const i = intake.data;
  const rows = (board.data?.data ?? []).filter((a) => !status || a.status === status);
  const count = (s: AppStatus) => (board.data?.data ?? []).filter((a) => a.status === s).length;
  const share = `Apply for ${i.title}: ${i.url}`;

  return (
    <>
      <Link to="/intakes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Applications
      </Link>
      <PageHeader
        eyebrow={`Applications ${day(i.opens_on)} – ${day(i.closes_on)}`}
        title={i.title}
        description={`${board.data?.places.taken ?? 0} of ${i.places} places offered${i.min_age ? ` · ${i.min_age}${i.max_age ? `–${i.max_age}` : '+'} years` : ''}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={intakeState[i.status].tone} dot>
              {intakeState[i.status].label}
            </Badge>
            {manage ? (
              <>
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  Change
                </Button>
                {i.status !== 'open' ? (
                  <Button loading={state.isPending} onClick={() => state.mutate('publish')}>
                    {i.status === 'closed' ? 'Open again' : 'Publish'}
                  </Button>
                ) : (
                  <Button variant="secondary" loading={state.isPending} onClick={() => state.mutate('close')}>
                    Close applications
                  </Button>
                )}
              </>
            ) : null}
          </div>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <Card>
          <div className="mb-4 flex flex-wrap gap-1 rounded-lg bg-line-soft p-1 sm:w-fit">
            {tabs.map(([key, label]) => (
              <button
                key={key}
                type="button"
                aria-pressed={status === key}
                onClick={() => setStatus(key)}
                className={cx(
                  'rounded-md px-3 py-1 text-small',
                  status === key ? 'bg-surface text-ink shadow-sm' : 'text-muted',
                )}
              >
                {label}
                {key && count(key) ? <span className="ml-1.5 text-faint tabular-nums">{count(key)}</span> : null}
              </button>
            ))}
          </div>
          {board.isPending ? (
            <Skeleton className="h-40" />
          ) : (
            <Table
              rows={rows}
              rowKey={(a) => a.id}
              empty={
                <p className="py-6 text-center text-small text-muted">
                  {i.status === 'open'
                    ? 'No applications here yet.'
                    : 'Publish the intake to start taking applications.'}
                </p>
              }
              columns={[
                {
                  key: 'who',
                  header: 'Applicant',
                  cell: (a) => (
                    <div className="min-w-0">
                      <div className="font-medium text-ink">{a.applicant?.name}</div>
                      <div className="text-small text-muted">
                        {[
                          a.reference,
                          a.applicant?.age != null ? `${a.applicant.age} years` : null,
                          a.applicant?.id_number,
                        ]
                          .filter(Boolean)
                          .join(' · ')}
                      </div>
                    </div>
                  ),
                },
                {
                  key: 'answers',
                  header: 'Answers',
                  cell: (a) => (
                    <span className="text-small">
                      {Object.entries(a.answers ?? {})
                        .map(([k, v]) => {
                          const label = i.questions?.find((q) => q.key === k)?.label ?? k;
                          return `${label}${label.endsWith('?') ? '' : ':'} ${typeof v === 'boolean' ? (v ? 'Yes' : 'No') : v}`;
                        })
                        .join(' · ') || '—'}
                    </span>
                  ),
                },
                {
                  key: 'status',
                  header: 'Status',
                  cell: (a) => (
                    <div className="grid gap-0.5">
                      <Badge tone={appState[a.status].tone} dot>
                        {appState[a.status].label}
                        {a.waitlist_position ? ` #${a.waitlist_position}` : ''}
                      </Badge>
                      {a.reason ? <span className="text-small text-muted">{a.reason}</span> : null}
                    </div>
                  ),
                },
                {
                  key: 'actions',
                  header: <span className="sr-only">Actions</span>,
                  cell: (a) =>
                    manage ? (
                      <div className="flex flex-wrap justify-end gap-1">
                        {['submitted', 'waitlisted'].includes(a.status) ? (
                          <Button size="sm" onClick={() => decide.mutate({ app: a.id, action: 'shortlist' })}>
                            Shortlist<span className="sr-only"> {a.applicant?.name}</span>
                          </Button>
                        ) : null}
                        {['submitted', 'shortlisted'].includes(a.status) ? (
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => decide.mutate({ app: a.id, action: 'waitlist' })}
                          >
                            Waiting list<span className="sr-only"> for {a.applicant?.name}</span>
                          </Button>
                        ) : null}
                        {['submitted', 'shortlisted', 'waitlisted'].includes(a.status) ? (
                          <Button size="sm" variant="secondary" onClick={() => setDeclining(a)}>
                            Decline<span className="sr-only"> {a.applicant?.name}</span>
                          </Button>
                        ) : null}
                        {a.status === 'declined' ? (
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => decide.mutate({ app: a.id, action: 'reopen' })}
                          >
                            Reopen<span className="sr-only"> {a.applicant?.name}</span>
                          </Button>
                        ) : null}
                      </div>
                    ) : null,
                },
              ]}
            />
          )}
        </Card>
        <Card className="h-fit">
          <CardHeader title="Share" subtitle="Applicants can apply from any phone. No app needed." />
          {i.qr ? <img src={i.qr} alt={`QR code for ${i.url}`} className="mx-auto mb-3 size-44" /> : null}
          <p className="mb-3 text-small break-all text-muted">{i.url}</p>
          <div className="grid gap-2">
            <Button
              variant="secondary"
              icon={<Copy className="size-4" />}
              onClick={() => void navigator.clipboard?.writeText(i.url).then(() => toast('Link copied'))}
            >
              Copy link
            </Button>
            <a
              href={`https://wa.me/?text=${encodeURIComponent(share)}`}
              target="_blank"
              rel="noreferrer"
              className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-line px-3 text-small font-medium text-ink hover:bg-line-soft"
            >
              <MessageCircle className="size-4" /> Share on WhatsApp
            </a>
            {i.qr ? (
              <a
                href={i.qr}
                download={`apply-${i.slug}.png`}
                className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-line px-3 text-small font-medium text-ink hover:bg-line-soft"
              >
                <Download className="size-4" /> Download QR code
              </a>
            ) : null}
          </div>
        </Card>
      </div>
      {editing ? <IntakeDialog intake={i} onClose={() => setEditing(false)} /> : null}
      {declining ? (
        <DeclineDialog
          name={declining.applicant?.name ?? 'this applicant'}
          busy={decide.isPending}
          onClose={() => setDeclining(null)}
          onDecline={(reason) => decide.mutate({ app: declining.id, action: 'decline', reason })}
        />
      ) : null}
    </>
  );
}

function DeclineDialog({
  name,
  busy,
  onClose,
  onDecline,
}: {
  name: string;
  busy: boolean;
  onClose: () => void;
  onDecline: (reason: string) => void;
}) {
  const [reason, setReason] = useState('');
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Decline ${name}`}
      description="They receive this reason by WhatsApp or SMS. Be kind and specific."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          onDecline(reason);
        }}
      >
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Reason</span>
          <textarea
            required
            minLength={5}
            className="min-h-20 rounded-lg border border-line bg-surface p-2"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant="danger" loading={busy}>
            Decline
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
