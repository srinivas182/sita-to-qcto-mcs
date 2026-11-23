import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, Plus, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Field, PageHeader, Select, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import {
  cohortState,
  MODES,
  shortDate,
  STAFF_ROLES,
  type CohortDetail,
  type CohortOptions,
  type ReadinessCheck,
  type ReadinessItem,
  type StaffRole,
} from './types';

const FIX: Record<string, { to: string; label: string }> = {
  catalogue: { to: '/programmes', label: 'Programmes' },
  programmes: { to: '/programmes', label: 'Programmes' },
  centres: { to: '/centres', label: 'Centres' },
  credentials: { to: '/people/credentials', label: 'Registrations' },
  employers: { to: '/employers', label: 'Employers' },
  agreements: { to: '/agreements', label: 'Agreements' },
};

/** One cohort: details, staff and workplaces on the left, the live readiness check on the right. */
export function CohortPage() {
  const { id } = useParams({ from: '/app/cohorts/$id' });
  const manage = useCan()('cohorts.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [problem, setProblem] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['cohorts', id],
    queryFn: () => call<{ data: CohortDetail }>('GET', `/cohorts/${id}`).then((r) => r.data),
  });
  const c = q.data;
  const editable = manage && (c?.status === 'draft' || c?.status === 'open');
  const options = useQuery({
    queryKey: ['cohorts', id, 'options'],
    queryFn: () => call<{ data: CohortOptions }>('GET', `/cohorts/${id}/options`).then((r) => r.data),
    enabled: editable,
  });
  const apply = (r: { data: CohortDetail }) => {
    setProblem(null);
    qc.setQueryData(['cohorts', id], r.data);
    void qc.invalidateQueries({ queryKey: ['cohorts'], exact: true });
  };
  const fail = (e: unknown) =>
    setProblem(e instanceof ApiError ? (e.fields.readiness?.[0] ?? errorMessage(e)) : errorMessage(e));
  const staff = useMutation({
    mutationFn: (list: { user_id: string; role: StaffRole }[]) =>
      call<{ data: CohortDetail }>('PUT', `/cohorts/${id}/staff`, { staff: list }),
    onSuccess: apply,
    onError: fail,
  });
  const places = useMutation({
    mutationFn: (list: { site_id: string; mentor_id: string | null; planned_learners: number }[]) =>
      call<{ data: CohortDetail }>('PUT', `/cohorts/${id}/placements`, { placements: list }),
    onSuccess: apply,
    onError: fail,
  });
  const action = useMutation({
    mutationFn: (what: 'open' | 'close' | 'cancel') => call<{ data: CohortDetail }>('POST', `/cohorts/${id}/${what}`),
    onSuccess: (r) => {
      apply(r);
      toast(r.data.status === 'open' ? 'Cohort open' : 'Saved');
    },
    onError: fail,
  });

  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError || !c) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const staffList = c.staff.map((s) => ({ user_id: s.user_id, role: s.role }));
  const placeList = c.placements.map((p) => ({
    site_id: p.site_id,
    mentor_id: p.mentor_id,
    planned_learners: p.planned_learners,
  }));
  const planned = c.placements.reduce((n, p) => n + p.planned_learners, 0);

  return (
    <>
      <Link to="/cohorts" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Cohorts
      </Link>
      <PageHeader
        eyebrow={`${c.qualification?.title ?? ''} · ${c.centre ?? ''}`}
        title={c.name}
        description={`${shortDate(c.intake_on)} to ${shortDate(c.planned_end_on)} · ${c.capacity} learners · ${MODES[c.mode] ?? c.mode}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={cohortState[c.status].tone} dot>
              {cohortState[c.status].label}
            </Badge>
            <Link
              to="/cohorts/$id/timetable"
              params={{ id: c.id }}
              className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-small font-medium text-ink hover:border-primary-200"
            >
              Timetable
            </Link>
            <Link
              to="/cohorts/$id/qcto"
              params={{ id: c.id }}
              className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-small font-medium text-ink hover:border-primary-200"
            >
              QCTO enrolment
            </Link>
            {manage && c.status === 'draft' ? (
              <>
                <Button
                  variant="secondary"
                  loading={action.isPending && action.variables === 'cancel'}
                  onClick={() => action.mutate('cancel')}
                >
                  Cancel plan
                </Button>
                <Button
                  disabled={!c.readiness.ok}
                  loading={action.isPending && action.variables === 'open'}
                  onClick={() => action.mutate('open')}
                >
                  Open cohort
                </Button>
              </>
            ) : null}
            {manage && c.status === 'open' ? (
              <Button
                variant="secondary"
                loading={action.isPending && action.variables === 'close'}
                onClick={() => action.mutate('close')}
              >
                Close cohort
              </Button>
            ) : null}
          </div>
        }
      />
      {problem ? (
        <div className="mb-4">
          <Alert tone="danger">{problem}</Alert>
        </div>
      ) : null}
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div className="grid content-start gap-4">
          <Card>
            <CardHeader
              title="1. Staff"
              subtitle="Registered for this qualification on the intake date. Nobody should moderate their own assessments."
            />
            <div className="grid gap-4">
              {STAFF_ROLES.map(({ role, label }) => {
                const mine = c.staff.filter((s) => s.role === role);
                const candidates = (options.data?.people[role] ?? []).filter(
                  (p) => !mine.some((m) => m.user_id === p.user_id),
                );
                return (
                  <div key={role}>
                    <h3 className="mb-1.5 text-small font-medium text-ink">{label}</h3>
                    <ul className="mb-2 flex flex-wrap gap-1.5">
                      {mine.length ? (
                        mine.map((s) => (
                          <li
                            key={s.user_id}
                            className="inline-flex items-center gap-1 rounded-full bg-line-soft py-0.5 pr-1 pl-2.5 text-small"
                          >
                            {s.name}
                            {editable ? (
                              <button
                                type="button"
                                className="rounded-full p-0.5 text-muted hover:bg-line hover:text-ink"
                                aria-label={`Remove ${s.name ?? 'person'} as ${role}`}
                                onClick={() =>
                                  staff.mutate(staffList.filter((x) => !(x.user_id === s.user_id && x.role === role)))
                                }
                              >
                                <Trash2 className="size-3.5" />
                              </button>
                            ) : null}
                          </li>
                        ))
                      ) : (
                        <li className="text-small text-muted">Nobody yet.</li>
                      )}
                    </ul>
                    {editable && candidates.length ? (
                      <Select
                        label={`Add a ${role}`}
                        value=""
                        onChange={(e) =>
                          e.target.value && staff.mutate([...staffList, { user_id: e.target.value, role }])
                        }
                      >
                        <option value="">Choose…</option>
                        {candidates.map((p) => (
                          <option key={p.user_id} value={p.user_id}>
                            {p.ok ? p.name : `${p.name}: ${p.reason}`}
                          </option>
                        ))}
                      </Select>
                    ) : null}
                  </div>
                );
              })}
            </div>
          </Card>
          <Card>
            <CardHeader
              title="2. Workplaces"
              subtitle={`Places planned for ${planned} of ${c.capacity} learners. Only workplaces approved for this qualification are listed, with the places they still have.`}
            />
            <ul className="mb-3 grid gap-2">
              {c.placements.map((p) => (
                <li
                  key={p.site_id}
                  className="flex items-center justify-between gap-3 rounded-lg border border-line p-3"
                >
                  <div className="min-w-0">
                    <div className="font-medium text-ink">
                      {p.employer}, {p.site}
                    </div>
                    <div className="text-small text-muted">
                      {p.planned_learners} learners{p.mentor ? ` · mentor ${p.mentor}` : ''}
                    </div>
                  </div>
                  {editable ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => places.mutate(placeList.filter((x) => x.site_id !== p.site_id))}
                    >
                      Remove<span className="sr-only"> {p.site}</span>
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
            {editable ? (
              <AddPlacement
                options={options.data}
                taken={c.placements.map((p) => p.site_id)}
                busy={places.isPending}
                onAdd={(p) => places.mutate([...placeList, p])}
              />
            ) : null}
          </Card>
        </div>
        <ReadinessPanel check={c.readiness} status={c.status} />
      </div>
    </>
  );
}

function AddPlacement({
  options,
  taken,
  busy,
  onAdd,
}: {
  options: CohortOptions | undefined;
  taken: string[];
  busy: boolean;
  onAdd: (p: { site_id: string; mentor_id: string | null; planned_learners: number }) => void;
}) {
  const [site, setSite] = useState('');
  const [mentor, setMentor] = useState('');
  const [count, setCount] = useState('');
  const sites = (options?.sites ?? []).filter((s) => s.approved && !taken.includes(s.id));
  const chosen = sites.find((s) => s.id === site);
  if (!options) return <Skeleton className="h-10" />;
  if (!sites.length)
    return (
      <p className="text-small text-muted">
        No more approved workplaces for this qualification. Add one under Employers.
      </p>
    );

  return (
    <form
      className="grid gap-2 border-t border-line pt-3 sm:grid-cols-[1fr_10rem_7rem_auto] sm:items-end"
      onSubmit={(e) => {
        e.preventDefault();
        onAdd({ site_id: site, mentor_id: mentor || null, planned_learners: Number(count) });
        setSite('');
        setMentor('');
        setCount('');
      }}
    >
      <Select label="Workplace" value={site} onChange={(e) => setSite(e.target.value)}>
        <option value="">Choose…</option>
        {sites.map((s) => (
          <option key={s.id} value={s.id}>
            {s.employer}, {s.name} ({s.free} free)
          </option>
        ))}
      </Select>
      <Select label="Mentor (optional)" value={mentor} onChange={(e) => setMentor(e.target.value)} disabled={!chosen}>
        <option value="">Any</option>
        {chosen?.mentors.map((m) => (
          <option key={m.id} value={m.id}>
            {m.name}
          </option>
        ))}
      </Select>
      <Field
        label="Learners"
        type="number"
        min={1}
        max={500}
        required
        value={count}
        onChange={(e) => setCount(e.target.value)}
      />
      <Button type="submit" icon={<Plus className="size-4" />} disabled={!site} loading={busy}>
        Add
      </Button>
    </form>
  );
}

const ICON = {
  pass: <CheckCircle2 className="size-4 shrink-0 text-success" aria-hidden />,
  warn: <AlertTriangle className="size-4 shrink-0 text-warning" aria-hidden />,
  fail: <XCircle className="size-4 shrink-0 text-danger" aria-hidden />,
};
const WORD = { pass: 'Ready', warn: 'Check', fail: 'Fix' };

function FixLink({ fix }: { fix: { to: string; label: string } | undefined }) {
  return fix ? (
    <Link to={fix.to} className="text-primary-700 hover:underline">
      Go to {fix.label}
    </Link>
  ) : null;
}

function ReadinessPanel({ check, status }: { check: ReadinessCheck; status: string }) {
  const groups = check.items.reduce<Record<string, ReadinessItem[]>>(
    (acc, i) => ({ ...acc, [i.group]: [...(acc[i.group] ?? []), i] }),
    {},
  );
  return (
    <Card className="h-fit lg:sticky lg:top-4">
      <CardHeader
        title="Readiness"
        subtitle={
          status === 'draft'
            ? check.ok
              ? 'Nothing fails: you can open the cohort.'
              : 'Fix the failing items to open the cohort.'
            : status === 'open'
              ? 'Checked again every day while the cohort runs.'
              : undefined
        }
      />
      <div className="mb-3 flex gap-2 text-small">
        <Badge tone="success">{check.counts.pass} ready</Badge>
        <Badge tone="warning">{check.counts.warn} to check</Badge>
        <Badge tone="danger">{check.counts.fail} to fix</Badge>
      </div>
      <div className="grid gap-3">
        {Object.entries(groups).map(([group, items]) => (
          <section key={group} aria-label={group}>
            <h3 className="mb-1 text-caption font-medium tracking-wide text-muted uppercase">{group}</h3>
            <ul className="grid gap-1.5">
              {items.map((i) => (
                <li key={i.key} className="flex gap-2">
                  <span className="mt-0.5">{ICON[i.status]}</span>
                  <div className="min-w-0 text-small">
                    <div className="font-medium text-ink">
                      <span className="sr-only">{WORD[i.status]}: </span>
                      {i.title}
                    </div>
                    {i.status !== 'pass' ? <div className="text-muted">{i.detail}</div> : null}
                    {i.fix && FIX[i.fix] ? <FixLink fix={FIX[i.fix]} /> : null}
                  </div>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </Card>
  );
}
