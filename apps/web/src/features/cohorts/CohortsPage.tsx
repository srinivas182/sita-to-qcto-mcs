import { useMutation, useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { AlertTriangle, CalendarRange, Plus } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, Dialog, EmptyState, Field, PageHeader, Select, Skeleton, Table } from '@/components/ui';
import { ApiError, call } from '@/lib/api';
import { useCan } from '@/lib/session';
import { cohortState, MODES, shortDate, type CohortDetail, type CohortRow } from './types';

/** Cohorts: each opens only when its readiness check passes, and is watched while it runs. */
export function CohortsPage() {
  const can = useCan();
  const [planning, setPlanning] = useState(false);
  const q = useQuery({ queryKey: ['cohorts'], queryFn: () => call<{ data: CohortRow[] }>('GET', '/cohorts') });

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Cohorts"
        description="Plan a cohort and it checks itself: the qualification is open, the centre is accredited, staff are registered, workplaces have room and agreements are signed. It opens only when nothing fails, and keeps checking while it runs."
        actions={
          can('cohorts.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setPlanning(true)}>
              Plan a cohort
            </Button>
          ) : null
        }
      />
      <Card>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(c) => c.id}
            empty={
              <EmptyState icon={<CalendarRange className="size-5" />} title="No cohorts yet">
                Plan your first intake.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Cohort',
                cell: (c) => (
                  <div className="min-w-0">
                    <Link to="/cohorts/$id" params={{ id: c.id }} className="font-medium text-ink hover:underline">
                      {c.name}
                    </Link>
                    <div className="text-small text-muted">
                      {c.qualification} · {c.centre}
                    </div>
                  </div>
                ),
              },
              {
                key: 'dates',
                header: 'Dates',
                cell: (c) => (
                  <span className="text-small whitespace-nowrap">
                    {shortDate(c.intake_on)} – {shortDate(c.planned_end_on)}
                  </span>
                ),
              },
              {
                key: 'size',
                header: 'Learners',
                cell: (c) => (
                  <span className="text-small">
                    <span className="tabular-nums">{c.capacity}</span> · {MODES[c.mode] ?? c.mode}
                  </span>
                ),
              },
              {
                key: 'status',
                header: 'Status',
                cell: (c) => (
                  <div className="flex flex-wrap items-center gap-1">
                    <Badge tone={cohortState[c.status].tone} dot>
                      {cohortState[c.status].label}
                    </Badge>
                    {c.at_risk ? (
                      <Badge tone="danger">
                        <AlertTriangle className="mr-0.5 size-3" /> Needs attention
                      </Badge>
                    ) : null}
                  </div>
                ),
              },
            ]}
          />
        )}
      </Card>
      {planning ? <PlanDialog onClose={() => setPlanning(false)} /> : null}
    </>
  );
}

function PlanDialog({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    name: '',
    qualification_id: '',
    centre_id: '',
    mode: 'contact',
    intake_on: '',
    planned_end_on: '',
    capacity: '20',
  });
  const programmes = useQuery({
    queryKey: ['programmes'],
    queryFn: () => call<{ data: { qualification: { id: string; title: string } }[] }>('GET', '/programmes'),
  });
  const centres = useQuery({
    queryKey: ['centres'],
    queryFn: () => call<{ data: { id: string; name: string }[] }>('GET', '/centres').then((r) => r.data),
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const save = useMutation({
    mutationFn: () => call<{ data: CohortDetail }>('POST', '/cohorts', { ...form, capacity: Number(form.capacity) }),
    onSuccess: (r) => void navigate({ to: '/cohorts/$id', params: { id: r.data.id } }),
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Plan a cohort"
      description="Start with the basics. Next you assign staff and workplaces, and the readiness check guides you."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field
          label="Name"
          placeholder="Bricklayer, February 2027"
          required
          value={form.name}
          onChange={set('name')}
          error={err('name')}
        />
        <Select label="Qualification" value={form.qualification_id} onChange={set('qualification_id')}>
          <option value="">Choose…</option>
          {programmes.data?.data.map(({ qualification: x }) => (
            <option key={x.id} value={x.id}>
              {x.title}
            </option>
          ))}
        </Select>
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Centre" value={form.centre_id} onChange={set('centre_id')}>
            <option value="">Choose…</option>
            {centres.data?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <Select label="Mode" value={form.mode} onChange={set('mode')}>
            {Object.entries(MODES).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </Select>
          <Field
            label="Intake"
            type="date"
            required
            value={form.intake_on}
            onChange={set('intake_on')}
            error={err('intake_on')}
          />
          <Field
            label="Planned end"
            type="date"
            required
            value={form.planned_end_on}
            onChange={set('planned_end_on')}
            error={err('planned_end_on')}
          />
          <Field
            label="Learners"
            type="number"
            min={1}
            max={500}
            required
            value={form.capacity}
            onChange={set('capacity')}
            error={err('capacity')}
          />
        </div>
        {err('qualification_id') || err('centre_id') ? (
          <p className="text-small text-danger">{err('qualification_id') ?? err('centre_id')}</p>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Continue
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
