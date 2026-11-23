import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { type Instrument, type Method, methodLabel } from './types';

interface PlanModule {
  id: string;
  code: string;
  title: string;
  kind: 'KM' | 'PM' | 'WM';
  credits: number;
  instruments: number;
}

interface Settings {
  max_attempts: number;
  random_pct: number;
  new_assessor_first: number;
  appeal_days: number;
  appeal_decide_days: number;
}

/** Summative assessments per module (from the QAS), and the provider's assessment policy. */
export function AssessmentPlanPage() {
  const { id } = useParams({ from: '/app/programmes/$id/assessments' });
  const [open, setOpen] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['assessment-plan', id],
    queryFn: () =>
      call<{ data: PlanModule[] }>('GET', '/assessment-plan', undefined, { qualification_id: id }).then((r) => r.data),
  });
  return (
    <>
      <Link to="/programmes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Programmes
      </Link>
      <PageHeader
        title="Summative assessments"
        description="Set how each module is assessed, from the qualification's assessment specification. Assessors record Competent or Not yet competent against these criteria."
      />
      <PolicyCard />
      {q.isPending ? (
        <Skeleton className="h-48" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No modules loaded">Load the curriculum's modules first.</EmptyState>
        </Card>
      ) : (
        <Card>
          <CardHeader title="Modules" />
          <ul className="divide-y divide-line">
            {q.data.map((m) => (
              <li key={m.id} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p className="font-medium text-ink">
                      {m.code} {m.title}
                    </p>
                    <p className="text-small text-muted">
                      {m.kind === 'KM' ? 'Knowledge' : m.kind === 'PM' ? 'Practical' : 'Work experience'} · {m.credits}{' '}
                      credits
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    {m.instruments > 0 ? (
                      <Badge tone="success">
                        {m.instruments} {m.instruments === 1 ? 'assessment' : 'assessments'}
                      </Badge>
                    ) : (
                      <Badge tone="warning">Not set</Badge>
                    )}
                    <Button size="sm" variant="secondary" onClick={() => setOpen(open === m.id ? null : m.id)}>
                      {open === m.id ? 'Close' : 'Edit'}
                      <span className="sr-only"> {m.code}</span>
                    </Button>
                  </div>
                </div>
                {open === m.id ? <ModuleEditor qualificationId={id} moduleId={m.id} /> : null}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  );
}

function PolicyCard() {
  const can = useCan();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['assessment-settings'],
    queryFn: () => call<{ data: Settings }>('GET', '/assessment-settings').then((r) => r.data),
  });
  const [form, setForm] = useState<Settings | null>(null);
  const save = useMutation({
    mutationFn: (s: Settings) => call('PUT', '/assessment-settings', s),
    onSuccess: () => {
      toast('Policy saved');
      setForm(null);
      void qc.invalidateQueries({ queryKey: ['assessment-settings'] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  if (!q.data) return null;
  const s = form ?? q.data;
  const edit = can('tenant.settings.manage');
  const num = (k: keyof Settings) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm({ ...s, [k]: Number(e.target.value) });
  return (
    <Card className="mb-4">
      <CardHeader title="Assessment and moderation policy" />
      <fieldset disabled={!edit} className="grid gap-3 sm:grid-cols-5">
        <legend className="sr-only">Policy</legend>
        <Field
          label="Attempts allowed"
          type="number"
          min={1}
          max={6}
          value={String(s.max_attempts)}
          onChange={num('max_attempts')}
        />
        <Field
          label="Moderation sample (%)"
          type="number"
          min={10}
          max={100}
          value={String(s.random_pct)}
          onChange={num('random_pct')}
        />
        <Field
          label="New assessor: first decisions moderated"
          type="number"
          min={0}
          max={50}
          value={String(s.new_assessor_first)}
          onChange={num('new_assessor_first')}
        />
        <Field
          label="Days to appeal"
          type="number"
          min={5}
          max={60}
          value={String(s.appeal_days)}
          onChange={num('appeal_days')}
        />
        <Field
          label="Days to decide an appeal"
          type="number"
          min={5}
          max={90}
          value={String(s.appeal_decide_days)}
          onChange={num('appeal_decide_days')}
        />
      </fieldset>
      {edit && form ? (
        <div className="mt-3">
          <Button loading={save.isPending} onClick={() => save.mutate(form)}>
            Save policy
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

type Draft = Omit<Instrument, 'id'> & { id?: string };

function ModuleEditor({ qualificationId, moduleId }: { qualificationId: string; moduleId: string }) {
  const can = useCan();
  const manage = can('programmes.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({
    queryKey: ['instruments', moduleId],
    queryFn: () =>
      call<{
        data: {
          methods: Method[];
          instruments: Instrument[];
          tasks: { id: string; title: string; pass_pct: number }[];
        };
      }>('GET', `/modules/${moduleId}/instruments`).then((r) => r.data),
  });
  const [drafts, setDrafts] = useState<Draft[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: (list: Draft[]) => call('PUT', `/modules/${moduleId}/instruments`, { instruments: list }),
    onSuccess: () => {
      toast('Assessments saved');
      setError(null);
      setDrafts(null);
      void qc.invalidateQueries({ queryKey: ['instruments', moduleId] });
      void qc.invalidateQueries({ queryKey: ['assessment-plan', qualificationId] });
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  if (q.isPending) return <Skeleton className="mt-3 h-24" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const methods = q.data.methods;
  const list: Draft[] = drafts ?? q.data.instruments;
  const set = (i: number, patch: Partial<Draft>) => setDrafts(list.map((d, n) => (n === i ? { ...d, ...patch } : d)));
  const blank: Draft = {
    method: methods[0] ?? 'observation',
    title: '',
    criteria: [{ key: '', text: '' }],
    pass_mark: null,
    instructions: null,
    task_id: null,
  };

  return (
    <fieldset disabled={!manage} className="mt-3 grid gap-3 rounded-lg bg-line-soft p-3">
      <legend className="sr-only">Assessments for this module</legend>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {list.length === 0 ? <p className="text-small text-muted">No assessment yet.</p> : null}
      {list.map((d, i) => (
        <div key={d.id ?? `new-${i}`} className="grid gap-2 rounded-lg border border-line bg-surface p-3">
          <div className="grid gap-2 sm:grid-cols-[12rem_1fr_8rem]">
            <Select
              label={`Method ${i + 1}`}
              value={d.method}
              onChange={(e) =>
                set(i, {
                  method: e.target.value as Method,
                  pass_mark: e.target.value === 'test' ? (d.pass_mark ?? 60) : null,
                  task_id: e.target.value === 'test' || e.target.value === 'assignment' ? d.task_id : null,
                })
              }
            >
              {methods.map((m) => (
                <option key={m} value={m}>
                  {methodLabel[m]}
                </option>
              ))}
            </Select>
            <Field label={`Title ${i + 1}`} value={d.title} onChange={(e) => set(i, { title: e.target.value })} />
            {d.method === 'test' ? (
              <Field
                label="Pass mark (%)"
                type="number"
                min={1}
                max={100}
                value={String(d.pass_mark ?? '')}
                onChange={(e) => set(i, { pass_mark: Number(e.target.value) })}
              />
            ) : (
              <span />
            )}
          </div>
          {(d.method === 'test' || d.method === 'assignment') && q.data.tasks.length ? (
            <Select
              label={`Taken online as (optional) for ${d.title || `assessment ${i + 1}`}`}
              value={d.task_id ?? ''}
              onChange={(e) => set(i, { task_id: e.target.value || null })}
            >
              <option value="">Not online: the assessor records it</option>
              {q.data.tasks.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.title} (task pass mark {t.pass_pct}%)
                </option>
              ))}
            </Select>
          ) : null}
          <p className="text-small font-medium text-ink">Criteria</p>
          {d.criteria.map((c, n) => (
            <div key={n} className="flex items-end gap-2">
              <Field
                className="flex-1"
                label={`Criterion ${n + 1} of ${d.title || `assessment ${i + 1}`}`}
                value={c.text}
                onChange={(e) =>
                  set(i, { criteria: d.criteria.map((x, k) => (k === n ? { ...x, text: e.target.value } : x)) })
                }
              />
              {d.criteria.length > 1 ? (
                <Button
                  size="sm"
                  variant="ghost"
                  aria-label={`Remove criterion ${n + 1}`}
                  onClick={() => set(i, { criteria: d.criteria.filter((_, k) => k !== n) })}
                >
                  <Trash2 className="size-4" />
                </Button>
              ) : null}
            </div>
          ))}
          {manage ? (
            <div className="flex flex-wrap gap-2">
              <Button
                size="sm"
                variant="ghost"
                onClick={() => set(i, { criteria: [...d.criteria, { key: '', text: '' }] })}
              >
                Add a criterion
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setDrafts(list.filter((_, n) => n !== i))}>
                Remove this assessment
              </Button>
            </div>
          ) : null}
        </div>
      ))}
      {manage ? (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" onClick={() => setDrafts([...list, blank])}>
            Add an assessment
          </Button>
          <Button
            size="sm"
            loading={save.isPending}
            disabled={drafts === null}
            onClick={() =>
              save.mutate(
                list.map((d) => ({
                  ...d,
                  criteria: d.criteria.map((c) => ({ key: c.key || null, text: c.text })),
                })) as Draft[],
              )
            }
          >
            Save
          </Button>
        </div>
      ) : null}
    </fieldset>
  );
}
