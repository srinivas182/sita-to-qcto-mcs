import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, ChevronLeft, ChevronRight, MapPin, Plus, User } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { useCan } from '@/lib/session';

export interface ClassSession {
  id: string;
  cohort_id: string;
  kind: 'knowledge' | 'practical';
  title: string | null;
  status: 'scheduled' | 'cancelled';
  cancel_reason: string | null;
  starts_at: string;
  ends_at: string;
  module: { id: string; code: string; title: string; kind: string } | null;
  room: { id: string; name: string } | null;
  venue: string | null;
  facilitator: { id: string; name: string } | null;
  series_id: string | null;
  notes: string | null;
}
interface Hours {
  module_id: string;
  code: string;
  title: string;
  kind: string;
  credits: number;
  notional_hours: number;
  planned_hours: number;
}
interface Timetable {
  data: ClassSession[];
  hours: Hours[];
  options: {
    modules: { id: string; code: string; title: string; kind: string }[];
    rooms: { id: string; name: string; kind: string; capacity: number | null }[];
    facilitators: { id: string; name: string }[];
  };
  cohort: { id: string; name: string; centre_id: string; starts_on: string; ends_on: string };
}

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
/** Session times come with the SA offset; the date and clock parts are read as given. */
export const dateOf = (iso: string) => iso.slice(0, 10);
export const clockOf = (iso: string) => iso.slice(11, 16);
const addDays = (ymd: string, n: number) => {
  const d = new Date(`${ymd}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
const mondayOf = (ymd: string) => {
  const dow = (new Date(`${ymd}T12:00:00Z`).getUTCDay() + 6) % 7;
  return addDays(ymd, -dow);
};
const longDay = (ymd: string) =>
  new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-ZA', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  });

/** A cohort's timetable by week, with planned hours per curriculum module. */
export function TimetablePage() {
  const { id } = useParams({ from: '/app/cohorts/$id/timetable' });
  const manage = useCan()('cohorts.manage');
  const [week, setWeek] = useState<string | null>(null);
  const [editing, setEditing] = useState<ClassSession | 'new' | null>(null);
  const [rooms, setRooms] = useState(false);
  const q = useQuery({
    queryKey: ['timetable', id],
    queryFn: () => call<Timetable>('GET', `/cohorts/${id}/sessions`),
  });

  const start = week ?? (q.data ? mondayOf(maxDate(today(), q.data.cohort.starts_on)) : mondayOf(today()));
  const days = useMemo(() => DAYS.map((_, i) => addDays(start, i)), [start]);

  if (q.isPending) return <Skeleton className="h-96" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const t = q.data;
  const inWeek = t.data.filter((s) => days.includes(dateOf(s.starts_at)));

  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> {t.cohort.name}
      </Link>
      <PageHeader
        title="Timetable"
        description="Knowledge and practical classes, linked to curriculum modules. Facilitators must be registered for the qualification on the day; clashes are refused. Learners see changes in the app."
        actions={
          manage ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setRooms(true)}>
                Rooms
              </Button>
              <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
                Add classes
              </Button>
            </div>
          ) : null
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <div className="mb-3 flex items-center justify-between gap-2">
            <Button
              variant="ghost"
              size="sm"
              icon={<ChevronLeft className="size-4" />}
              onClick={() => setWeek(addDays(start, -7))}
            >
              Previous<span className="sr-only"> week</span>
            </Button>
            <h2 className="text-body font-semibold text-ink">Week of {longDay(start)}</h2>
            <Button variant="ghost" size="sm" onClick={() => setWeek(addDays(start, 7))}>
              Next<span className="sr-only"> week</span> <ChevronRight className="size-4" />
            </Button>
          </div>
          {inWeek.length === 0 ? <p className="py-6 text-center text-small text-muted">No classes this week.</p> : null}
          <div className="grid gap-4">
            {days.map((d) => {
              const list = inWeek.filter((s) => dateOf(s.starts_at) === d);
              if (!list.length) return null;
              return (
                <section key={d} aria-label={longDay(d)}>
                  <h3 className="mb-2 text-small font-semibold text-muted">{longDay(d)}</h3>
                  <ul className="grid gap-2">
                    {list.map((s) => (
                      <li key={s.id}>
                        <button
                          type="button"
                          disabled={!manage}
                          onClick={() => setEditing(s)}
                          className={`grid w-full gap-1 rounded-lg border p-3 text-left ${s.status === 'cancelled' ? 'border-line bg-line-soft opacity-70' : 'border-line hover:border-primary-200'}`}
                        >
                          <span className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium text-ink">
                              {clockOf(s.starts_at)}–{clockOf(s.ends_at)} · {s.title ?? 'Class'}
                            </span>
                            {s.status === 'cancelled' ? (
                              <Badge tone="neutral">Cancelled</Badge>
                            ) : (
                              <Badge tone={s.kind === 'practical' ? 'warning' : 'info'}>
                                {s.kind === 'practical' ? 'Practical' : 'Knowledge'}
                              </Badge>
                            )}
                          </span>
                          <span className="flex flex-wrap gap-x-4 gap-y-1 text-small text-muted">
                            {s.module ? <span>{s.module.code}</span> : null}
                            <span className="inline-flex items-center gap-1">
                              <MapPin className="size-3.5" aria-hidden /> {s.room?.name ?? s.venue ?? 'No venue'}
                            </span>
                            <span className="inline-flex items-center gap-1">
                              <User className="size-3.5" aria-hidden /> {s.facilitator?.name ?? 'No facilitator'}
                            </span>
                          </span>
                          {s.cancel_reason ? <span className="text-small text-muted">{s.cancel_reason}</span> : null}
                        </button>
                      </li>
                    ))}
                  </ul>
                </section>
              );
            })}
          </div>
        </Card>
        <Card>
          <CardHeader title="Hours per module" subtitle="Planned classes against notional hours (10 per credit)." />
          <ul className="grid gap-3">
            {t.hours.map((h) => {
              const pct = h.notional_hours ? Math.min(100, Math.round((h.planned_hours / h.notional_hours) * 100)) : 0;
              return (
                <li key={h.module_id} className="text-small">
                  <div className="flex justify-between gap-2">
                    <span className="text-ink">
                      {h.code} <span className="text-muted">{h.title}</span>
                    </span>
                    <span className="tabular-nums text-muted">
                      {h.planned_hours} / {h.notional_hours} h
                    </span>
                  </div>
                  <div
                    className="mt-1 h-1.5 rounded-full bg-line-soft"
                    role="progressbar"
                    aria-label={`${h.code} planned hours`}
                    aria-valuenow={pct}
                    aria-valuemin={0}
                    aria-valuemax={100}
                  >
                    <div className="h-1.5 rounded-full bg-primary-600" style={{ width: `${pct}%` }} />
                  </div>
                </li>
              );
            })}
            {t.hours.length === 0 ? (
              <li className="text-small text-muted">The qualification has no modules loaded yet.</li>
            ) : null}
          </ul>
        </Card>
      </div>
      {editing ? (
        <SessionDialog timetable={t} session={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
      ) : null}
      {rooms ? <RoomsDialog centreId={t.cohort.centre_id} cohortId={id} onClose={() => setRooms(false)} /> : null}
    </>
  );
}

const today = () => new Date().toISOString().slice(0, 10);
const maxDate = (a: string, b: string) => (a > b ? a : b);

function SessionDialog({
  timetable: t,
  session,
  onClose,
}: {
  timetable: Timetable;
  session: ClassSession | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const [form, setForm] = useState({
    kind: session?.kind ?? 'knowledge',
    module_id: session?.module?.id ?? '',
    title: session && !session.module ? (session.title ?? '') : '',
    date: session ? dateOf(session.starts_at) : maxDate(today(), t.cohort.starts_on),
    start: session ? clockOf(session.starts_at) : '08:00',
    end: session ? clockOf(session.ends_at) : '12:00',
    room_id: session?.room?.id ?? '',
    venue: session?.venue ?? '',
    facilitator_id: session?.facilitator?.id ?? '',
  });
  const [repeat, setRepeat] = useState<{ on: boolean; days: number[]; until: string }>({
    on: false,
    days: [1],
    until: t.cohort.ends_on,
  });
  const [scope, setScope] = useState<'one' | 'following'>('one');
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const done = (msg: string) => {
    toast(msg);
    void qc.invalidateQueries({ queryKey: ['timetable'] });
    onClose();
  };
  const body = {
    ...form,
    module_id: form.module_id || null,
    title: form.title || null,
    room_id: form.room_id || null,
    venue: form.room_id ? null : form.venue || null,
    facilitator_id: form.facilitator_id || null,
  };
  const save = useMutation({
    mutationFn: () =>
      session
        ? call<{ data: ClassSession[] }>('PATCH', `/class-sessions/${session.id}`, {
            ...body,
            ...(scope === 'following' ? { date: undefined } : {}),
            scope,
          })
        : call<{ data: ClassSession[] }>('POST', `/cohorts/${t.cohort.id}/sessions`, {
            ...body,
            repeat: repeat.on ? { days: repeat.days, until: repeat.until } : null,
          }),
    onSuccess: (r) => done(`${r.data.length} ${r.data.length === 1 ? 'class' : 'classes'} saved`),
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { kind: [errorMessage(e)] }),
  });
  const cancel = useMutation({
    mutationFn: () =>
      call<{ data: ClassSession[] }>('POST', `/class-sessions/${session!.id}/cancel`, { reason, scope }),
    onSuccess: (r) => done(`${r.data.length} cancelled`),
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { reason: [errorMessage(e)] }),
  });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const firstError = Object.values(errors)[0]?.[0];

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={session ? 'Change a class' : 'Add classes'}>
      <div className="grid gap-3">
        {firstError ? <Alert tone="danger">{firstError}</Alert> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Kind" value={form.kind} onChange={set('kind')}>
            <option value="knowledge">Knowledge</option>
            <option value="practical">Practical</option>
          </Select>
          <Select label="Module" value={form.module_id} onChange={set('module_id')}>
            <option value="">Not linked to a module</option>
            {t.options.modules.map((m) => (
              <option key={m.id} value={m.id}>
                {m.code} {m.title}
              </option>
            ))}
          </Select>
        </div>
        {!form.module_id ? (
          <Field label="Title" value={form.title} onChange={set('title')} error={err('title')} />
        ) : null}
        <div className="grid gap-3 sm:grid-cols-3">
          <Field
            label="Date"
            type="date"
            value={form.date}
            disabled={!!session && scope === 'following'}
            onChange={set('date')}
            error={err('date')}
          />
          <Field label="Starts" type="time" value={form.start} onChange={set('start')} error={err('start')} />
          <Field label="Ends" type="time" value={form.end} onChange={set('end')} error={err('end')} />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Room" value={form.room_id} onChange={set('room_id')}>
            <option value="">Somewhere else</option>
            {t.options.rooms.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
          {form.room_id ? null : <Field label="Venue" value={form.venue} onChange={set('venue')} />}
          <Select label="Facilitator" value={form.facilitator_id} onChange={set('facilitator_id')}>
            <option value="">Not yet chosen</option>
            {t.options.facilitators.map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
          </Select>
        </div>
        {session ? (
          session.series_id ? (
            <fieldset className="grid gap-2 text-small">
              <legend className="mb-1 font-medium text-ink">Apply to</legend>
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  className="size-5"
                  name="scope"
                  checked={scope === 'one'}
                  onChange={() => setScope('one')}
                />{' '}
                This class only
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="radio"
                  className="size-5"
                  name="scope"
                  checked={scope === 'following'}
                  onChange={() => setScope('following')}
                />{' '}
                This and the following classes in the series
              </label>
            </fieldset>
          ) : null
        ) : (
          <fieldset className="grid gap-2 text-small">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={repeat.on}
                onChange={(e) => setRepeat({ ...repeat, on: e.target.checked })}
              />
              Repeat every week
            </label>
            {repeat.on ? (
              <>
                <div className="flex flex-wrap gap-3" role="group" aria-label="Days of the week">
                  {DAYS.map((d, i) => (
                    <label key={d} className="flex items-center gap-1">
                      <input
                        type="checkbox"
                        checked={repeat.days.includes(i + 1)}
                        onChange={(e) =>
                          setRepeat({
                            ...repeat,
                            days: e.target.checked ? [...repeat.days, i + 1] : repeat.days.filter((x) => x !== i + 1),
                          })
                        }
                      />
                      {d}
                    </label>
                  ))}
                </div>
                <Field
                  label="Until"
                  type="date"
                  value={repeat.until}
                  onChange={(e) => setRepeat({ ...repeat, until: e.target.value })}
                  error={err('repeat') ?? err('repeat.until')}
                />
              </>
            ) : null}
          </fieldset>
        )}
        {cancelling ? (
          <div className="grid gap-2 rounded-lg border border-line p-3">
            <Field
              label="Why is it cancelled?"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              error={err('reason')}
            />
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setCancelling(false)}>
                Back
              </Button>
              <Button
                variant="danger"
                loading={cancel.isPending}
                disabled={reason.trim().length < 3}
                onClick={() => cancel.mutate()}
              >
                Cancel {scope === 'following' ? 'these classes' : 'the class'}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap justify-between gap-2">
            {session ? (
              <div className="flex gap-2">
                <Link
                  to="/classes/$id"
                  params={{ id: session.id }}
                  className="inline-flex h-10 items-center rounded-lg px-3 text-small font-medium text-primary-700 hover:bg-line-soft"
                >
                  Attendance
                </Link>
                {session.status === 'scheduled' ? (
                  <Button variant="ghost" onClick={() => setCancelling(true)}>
                    Cancel the class
                  </Button>
                ) : null}
              </div>
            ) : (
              <span />
            )}
            <div className="flex gap-2">
              <Button variant="secondary" onClick={onClose}>
                Close
              </Button>
              <Button loading={save.isPending} onClick={() => save.mutate()}>
                Save
              </Button>
            </div>
          </div>
        )}
      </div>
    </Dialog>
  );
}

function RoomsDialog({ centreId, cohortId, onClose }: { centreId: string; cohortId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['rooms', centreId],
    queryFn: () =>
      call<{
        data: { id: string; name: string; kind: string; capacity: number | null; active: boolean }[];
        kinds: Record<string, string>;
      }>('GET', `/centres/${centreId}/rooms`),
  });
  const [form, setForm] = useState({ name: '', kind: 'classroom', capacity: '' });
  const [error, setError] = useState<string | null>(null);
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['rooms', centreId] });
    void qc.invalidateQueries({ queryKey: ['timetable', cohortId] });
  };
  const add = useMutation({
    mutationFn: () =>
      call('POST', `/centres/${centreId}/rooms`, { ...form, capacity: form.capacity ? Number(form.capacity) : null }),
    onSuccess: () => {
      setForm({ name: '', kind: 'classroom', capacity: '' });
      setError(null);
      refresh();
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const toggle = useMutation({
    mutationFn: (r: { id: string; active: boolean }) =>
      call('PATCH', `/centres/${centreId}/rooms/${r.id}`, { active: !r.active }),
    onSuccess: refresh,
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Rooms at this centre"
      description="Rooms are checked for double bookings."
    >
      <div className="grid gap-3">
        <ul className="grid gap-2">
          {(q.data?.data ?? []).map((r) => (
            <li key={r.id} className="flex items-center justify-between gap-2 text-small">
              <span className={r.active ? 'text-ink' : 'text-muted line-through'}>
                {r.name}{' '}
                <span className="text-muted">
                  · {q.data?.kinds[r.kind] ?? r.kind}
                  {r.capacity ? ` · ${r.capacity} seats` : ''}
                </span>
              </span>
              <Button size="sm" variant="ghost" onClick={() => toggle.mutate(r)}>
                {r.active ? 'Retire' : 'Use again'}
                <span className="sr-only"> {r.name}</span>
              </Button>
            </li>
          ))}
        </ul>
        <div className="grid items-end gap-2 sm:grid-cols-[1fr_9rem_6rem_auto]">
          <Field label="New room" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Select label="Kind" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
            {Object.entries(q.data?.kinds ?? {}).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </Select>
          <Field
            label="Seats"
            type="number"
            min={1}
            value={form.capacity}
            onChange={(e) => setForm({ ...form, capacity: e.target.value })}
          />
          <Button loading={add.isPending} disabled={!form.name.trim()} onClick={() => add.mutate()}>
            Add
          </Button>
        </div>
        {error ? <p className="text-small text-danger">{error}</p> : null}
      </div>
    </Dialog>
  );
}
