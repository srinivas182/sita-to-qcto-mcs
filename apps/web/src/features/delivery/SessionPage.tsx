import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Maximize2, WifiOff } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';
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
import { ApiError, call, errorMessage } from '@/lib/api';
import { clockOf, type ClassSession } from './TimetablePage';
import { attendanceTone, STATUS_LABEL, type AttendanceMark, type AttendanceStatus } from './types';

interface Roster {
  session: ClassSession & { cohort: string | null };
  learners: { user_id: string; name: string; attendance: AttendanceMark | null }[];
  counts: { expected: number; in: number; excused: number; to_review: number };
}

/** One class: the changing check-in code, the class list, marking and reviews. */
export function SessionPage() {
  const { id } = useParams({ from: '/app/classes/$id' });
  const qc = useQueryClient();
  const toast = useToast();
  const [marking, setMarking] = useState<{ user_id: string; name: string } | null>(null);
  const [showCode, setShowCode] = useState(false);
  const q = useQuery({
    queryKey: ['class', id],
    queryFn: () => call<{ data: Roster }>('GET', `/class-sessions/${id}/attendance`).then((r) => r.data),
    refetchInterval: 15_000,
  });
  const review = useMutation({
    mutationFn: (v: { id: string; accept: boolean }) =>
      call('POST', `/attendances/${v.id}/review`, {
        accept: v.accept,
        reason: v.accept ? null : 'Not accepted by the facilitator',
      }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['class', id] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (q.isPending) return <Skeleton className="h-96" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const r = q.data;
  const s = r.session;

  return (
    <>
      <Link to="/classes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> My classes
      </Link>
      <PageHeader
        eyebrow={`${r.session.cohort ?? ''} · ${s.starts_at.slice(0, 10)} ${clockOf(s.starts_at)}–${clockOf(s.ends_at)}`}
        title={s.title ?? 'Class'}
        description={[s.module?.code, s.room?.name ?? s.venue].filter(Boolean).join(' · ')}
        actions={
          s.status === 'cancelled' ? (
            <Badge tone="neutral">Cancelled</Badge>
          ) : (
            <Button icon={<Maximize2 className="size-4" />} onClick={() => setShowCode(true)}>
              Show check-in code
            </Button>
          )
        }
      />
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Card>
          <p className="text-small text-muted">Checked in</p>
          <p className="text-[24px] font-semibold tabular-nums text-ink">
            {r.counts.in} <span className="text-body text-muted">of {r.counts.expected}</span>
          </p>
        </Card>
        <Card>
          <p className="text-small text-muted">Excused</p>
          <p className="text-[24px] font-semibold tabular-nums text-ink">{r.counts.excused}</p>
        </Card>
        <Card>
          <p className="text-small text-muted">To review</p>
          <p className="text-[24px] font-semibold tabular-nums text-ink">{r.counts.to_review}</p>
        </Card>
      </div>
      <Card>
        <CardHeader
          title="Class list"
          subtitle="Learners check in by scanning the code. Mark anyone without a phone, with a reason."
        />
        <ul className="divide-y divide-line">
          {r.learners.map((l) => {
            const a = l.attendance;
            return (
              <li key={l.user_id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <div className="min-w-0">
                  <p className="font-medium text-ink">{l.name}</p>
                  <p className="text-small text-muted">
                    {a?.checked_in_at ? `In ${clockOf(a.checked_in_at)}` : 'Not checked in'}
                    {a?.checked_out_at ? ` · out ${clockOf(a.checked_out_at)}` : ''}
                    {a?.minutes_late ? ` · ${a.minutes_late} min late` : ''}
                    {a?.distance_m != null ? ` · ${a.distance_m} m from the centre` : ''}
                    {a?.method === 'facilitator' && a.reason ? ` · marked: ${a.reason}` : ''}
                  </p>
                  {a?.needs_review ? (
                    <p className="flex items-center gap-1 text-small text-warning">
                      {a.offline ? <WifiOff className="size-3.5" aria-hidden /> : null}
                      {a.review_reason}
                      {a.reason && a.method === 'note' ? `: ${a.reason}` : ''}
                    </p>
                  ) : null}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {a ? (
                    <Badge tone={attendanceTone[a.status]} dot>
                      {STATUS_LABEL[a.status]}
                    </Badge>
                  ) : null}
                  {a?.needs_review ? (
                    <>
                      <Button size="sm" variant="secondary" onClick={() => review.mutate({ id: a.id, accept: true })}>
                        Accept<span className="sr-only"> for {l.name}</span>
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => review.mutate({ id: a.id, accept: false })}>
                        Mark absent<span className="sr-only"> {l.name}</span>
                      </Button>
                    </>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => setMarking(l)}>
                    Mark<span className="sr-only"> {l.name}</span>
                  </Button>
                </div>
              </li>
            );
          })}
          {r.learners.length === 0 ? (
            <li className="py-4 text-small text-muted">No learners are enrolled yet.</li>
          ) : null}
        </ul>
      </Card>
      {marking ? <MarkDialog sessionId={id} learner={marking} onClose={() => setMarking(null)} /> : null}
      {showCode ? <CodeDialog sessionId={id} title={s.title ?? 'Class'} onClose={() => setShowCode(false)} /> : null}
    </>
  );
}

/** The code changes every 30 seconds; a photo of it is useless a minute later. */
function CodeDialog({ sessionId, title, onClose }: { sessionId: string; title: string; onClose: () => void }) {
  const [svg, setSvg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout>;
    let stopped = false;
    const load = async () => {
      try {
        const r = await call<{ data: { token: string; refresh_in: number } }>('GET', `/class-sessions/${sessionId}/qr`);
        const url = `${window.location.origin}/c/${r.data.token}`;
        if (!stopped) setSvg(await QRCode.toString(url, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' }));
        setError(null);
        timer = setTimeout(() => void load(), Math.max(1, r.data.refresh_in) * 1000);
      } catch (e) {
        setError(errorMessage(e));
        timer = setTimeout(() => void load(), 5000);
      }
    };
    void load();
    return () => {
      stopped = true;
      clearTimeout(timer);
    };
  }, [sessionId]);
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Check in: ${title}`}
      description="Scan with the app, or the phone's camera. The code changes every 30 seconds."
    >
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {svg ? (
        <div
          role="img"
          aria-label="Check-in code"
          className="mx-auto w-full max-w-[420px] rounded-xl bg-white p-3"
          dangerouslySetInnerHTML={{ __html: svg }}
        />
      ) : (
        <Skeleton className="mx-auto aspect-square w-full max-w-[420px]" />
      )}
    </Dialog>
  );
}

function MarkDialog({
  sessionId,
  learner,
  onClose,
}: {
  sessionId: string;
  learner: { user_id: string; name: string };
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [form, setForm] = useState<{ status: AttendanceStatus; reason: string }>({ status: 'present', reason: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () => call('POST', `/class-sessions/${sessionId}/attendance`, { user_id: learner.user_id, ...form }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['class', sessionId] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { status: [errorMessage(e)] }),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Mark ${learner.name}`}
      description="The reason is kept on the record."
    >
      <div className="grid gap-3">
        <Select
          label="Status"
          value={form.status}
          onChange={(e) => setForm({ ...form, status: e.target.value as AttendanceStatus })}
        >
          {Object.entries(STATUS_LABEL).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </Select>
        <Field
          label="Reason"
          hint="For example: no phone, sick note seen, left for the clinic"
          value={form.reason}
          onChange={(e) => setForm({ ...form, reason: e.target.value })}
          error={errors.reason?.[0] ?? errors.status?.[0] ?? errors.user_id?.[0]}
        />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={form.reason.trim().length < 3} onClick={() => save.mutate()}>
            Save
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
