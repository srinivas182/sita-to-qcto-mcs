import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download, Send } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  Table,
  useToast,
  type Tone,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';

export interface RegisterRow {
  id: string;
  cohort_id: string;
  cohort: string | null;
  month: string;
  status: 'signing' | 'complete' | 'submitted';
  submitted_on: string | null;
  submitted_note: string | null;
  counts: {
    learners: number;
    signed: number;
    disputed: number;
    facilitators_signed: number;
    facilitators: number;
    mentors?: number;
    mentors_signed?: number;
  };
  signatures: {
    id: string;
    user_id: string;
    name: string | null;
    role: 'learner' | 'facilitator' | 'mentor';
    status: 'pending' | 'signed' | 'disputed';
    dispute: string | null;
    signed_at: string | null;
  }[];
}

export const registerState: Record<RegisterRow['status'], { label: string; tone: Tone }> = {
  signing: { label: 'Waiting for signatures', tone: 'warning' },
  complete: { label: 'Ready to send', tone: 'primary' },
  submitted: { label: 'Sent to the SETA', tone: 'success' },
};

export const monthName = (ym: string) =>
  new Date(`${ym}-15T12:00:00Z`).toLocaleDateString('en-ZA', { month: 'long', year: 'numeric', timeZone: 'UTC' });

/** Download a staff file (PDF) through the API with the session cookie. */
export async function downloadFrom(path: string, fallback: string) {
  const res = await fetch(`/v1${path}`, { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  if (!res.ok) throw new Error('The file could not be made. Try again.');
  const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? fallback;
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}

/** Monthly attendance registers for the SETA: made after each month, signed in the app, sent. */
export function RegistersPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/registers' });
  const manage = useCan()('cohorts.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [making, setMaking] = useState(false);
  const [sending, setSending] = useState<RegisterRow | null>(null);
  const [viewing, setViewing] = useState<RegisterRow | null>(null);
  const q = useQuery({
    queryKey: ['registers', id],
    queryFn: () => call<{ data: RegisterRow[] }>('GET', `/cohorts/${id}/registers`).then((r) => r.data),
  });
  const remind = useMutation({
    mutationFn: (r: RegisterRow) => call<{ data: { reminded: number } }>('POST', `/registers/${r.id}/remind`),
    onSuccess: (r) => toast(`Reminded ${r.data.reminded} ${r.data.reminded === 1 ? 'person' : 'people'}`),
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;

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
        title="Attendance registers"
        description="A SETA pays stipends on each month's register. Registers are made in the first days of the month; learners and facilitators sign them in the app."
        actions={manage ? <Button onClick={() => setMaking(true)}>Make a register</Button> : null}
      />
      <Card>
        {q.data.length ? (
          <Table
            rows={q.data}
            rowKey={(r) => r.id}
            columns={[
              {
                key: 'month',
                header: 'Month',
                cell: (r) => <span className="font-medium text-ink">{monthName(r.month)}</span>,
              },
              {
                key: 'learners',
                header: 'Learners signed',
                cell: (r) => (
                  <span className="text-small">
                    {r.counts.signed} of {r.counts.learners}
                    {r.counts.disputed ? <span className="text-danger"> · {r.counts.disputed} disputed</span> : null}
                  </span>
                ),
              },
              {
                key: 'facilitators',
                header: 'Facilitators',
                cell: (r) => (
                  <span className="text-small">
                    {r.counts.facilitators_signed} of {r.counts.facilitators}
                    {r.counts.mentors ? (
                      <span className="block text-muted">
                        Mentors {r.counts.mentors_signed ?? 0} of {r.counts.mentors}
                      </span>
                    ) : null}
                  </span>
                ),
              },
              {
                key: 'status',
                header: 'Status',
                cell: (r) => (
                  <Badge tone={registerState[r.status].tone} dot>
                    {registerState[r.status].label}
                    {r.submitted_on ? ` ${r.submitted_on}` : ''}
                  </Badge>
                ),
              },
              {
                key: 'actions',
                header: <span className="sr-only">Actions</span>,
                cell: (r) => (
                  <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="ghost" onClick={() => setViewing(r)}>
                      Signatures<span className="sr-only"> for {monthName(r.month)}</span>
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      icon={<Download className="size-4" />}
                      onClick={() =>
                        void downloadFrom(`/registers/${r.id}/pdf`, 'register.pdf').catch((e: unknown) =>
                          toast(errorMessage(e), 'error'),
                        )
                      }
                    >
                      PDF<span className="sr-only"> for {monthName(r.month)}</span>
                    </Button>
                    {manage && r.status === 'signing' ? (
                      <Button size="sm" variant="ghost" onClick={() => remind.mutate(r)}>
                        Remind<span className="sr-only"> for {monthName(r.month)}</span>
                      </Button>
                    ) : null}
                    {manage && r.status === 'complete' ? (
                      <Button
                        size="sm"
                        variant="secondary"
                        icon={<Send className="size-4" />}
                        onClick={() => setSending(r)}
                      >
                        Mark as sent<span className="sr-only"> for {monthName(r.month)}</span>
                      </Button>
                    ) : null}
                  </div>
                ),
              },
            ]}
          />
        ) : (
          <EmptyState title="No registers yet">
            Registers are made in the first days of each month for the month before.
          </EmptyState>
        )}
      </Card>
      {making ? <MakeDialog cohortId={id} onClose={() => setMaking(false)} /> : null}
      {sending ? (
        <SentDialog
          register={sending}
          onClose={() => setSending(null)}
          onDone={() => void qc.invalidateQueries({ queryKey: ['registers', id] })}
        />
      ) : null}
      {viewing ? <SignaturesDialog register={viewing} onClose={() => setViewing(null)} /> : null}
    </>
  );
}

function lastMonths(n: number): string[] {
  const out: string[] = [];
  const d = new Date();
  for (let i = 1; i <= n; i++) {
    const m = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() - i, 15));
    out.push(m.toISOString().slice(0, 7));
  }
  return out;
}

function MakeDialog({ cohortId, onClose }: { cohortId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [month, setMonth] = useState(lastMonths(1)[0]!);
  const make = useMutation({
    mutationFn: () => call('POST', `/cohorts/${cohortId}/registers`, { month }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['registers', cohortId] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Make a register"
      description="Making a month's register again (before it is sent) replaces it, and everyone signs the new one."
    >
      <div className="grid gap-3">
        <Select label="Month" value={month} onChange={(e) => setMonth(e.target.value)}>
          {lastMonths(12).map((m) => (
            <option key={m} value={m}>
              {monthName(m)}
            </option>
          ))}
        </Select>
        {make.isError ? <p className="text-small text-danger">{errorMessage(make.error)}</p> : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={make.isPending} onClick={() => make.mutate()}>
            Make it
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

function SentDialog({ register, onClose, onDone }: { register: RegisterRow; onClose: () => void; onDone: () => void }) {
  const [form, setForm] = useState({ sent_on: new Date().toISOString().slice(0, 10), note: '' });
  const save = useMutation({
    mutationFn: () => call('POST', `/registers/${register.id}/submitted`, { ...form, note: form.note || null }),
    onSuccess: () => {
      onDone();
      onClose();
    },
  });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={`Sent: ${monthName(register.month)}`}>
      <div className="grid gap-3">
        <Field
          label="Date sent"
          type="date"
          value={form.sent_on}
          onChange={(e) => setForm({ ...form, sent_on: e.target.value })}
        />
        <Field label="Note (optional)" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
        {save.isError ? <p className="text-small text-danger">{errorMessage(save.error)}</p> : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

function SignaturesDialog({ register, onClose }: { register: RegisterRow; onClose: () => void }) {
  const tone = { pending: 'neutral', signed: 'success', disputed: 'danger' } as const;
  const label = { pending: 'Not signed', signed: 'Signed', disputed: 'Disputed' } as const;
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={`Signatures: ${monthName(register.month)}`}>
      <ul className="grid gap-2">
        {register.signatures.map((s) => (
          <li key={s.id} className="flex flex-wrap items-start justify-between gap-2 text-small">
            <span>
              <span className="text-ink">{s.name}</span>{' '}
              <span className="text-muted">
                · {s.role === 'facilitator' ? 'Facilitator' : s.role === 'mentor' ? 'Workplace mentor' : 'Learner'}
              </span>
              {s.dispute ? <span className="block text-danger">{s.dispute}</span> : null}
            </span>
            <Badge tone={tone[s.status]}>{label[s.status]}</Badge>
          </li>
        ))}
      </ul>
    </Dialog>
  );
}
