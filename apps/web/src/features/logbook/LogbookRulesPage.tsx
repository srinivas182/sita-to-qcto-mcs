import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import type { Activity, Rule } from './types';

interface WorkModule {
  id: string;
  code: string;
  title: string;
  credits: number;
  default_hours: number;
  rule: Rule | null;
}

/** Logbook rules per work experience module: the activities learners log and the hours needed. */
export function LogbookRulesPage() {
  const { id } = useParams({ from: '/app/programmes/$id/logbook' });
  const q = useQuery({
    queryKey: ['logbook-rules', id],
    queryFn: () =>
      call<{ data: WorkModule[] }>('GET', '/logbook-rules', undefined, { qualification_id: id }).then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  return (
    <>
      <Link to="/programmes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Programmes
      </Link>
      <PageHeader
        title="Workplace logbook"
        description="For each work experience module, list what learners do on site. Learners log time against these; mentors sign each week."
      />
      {q.data.length === 0 ? (
        <Card>
          <EmptyState title="No work experience modules">This qualification has no workplace modules.</EmptyState>
        </Card>
      ) : (
        <div className="grid gap-4">
          {q.data.map((m) => (
            <ModuleRules key={m.id} qualificationId={id} m={m} />
          ))}
        </div>
      )}
    </>
  );
}

function ModuleRules({ qualificationId, m }: { qualificationId: string; m: WorkModule }) {
  const can = useCan();
  const manage = can('cohorts.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [activities, setActivities] = useState<Activity[]>(
    m.rule?.activities ?? [{ key: '', title: '', photo: false }],
  );
  const [hours, setHours] = useState(String(m.rule?.hours_needed ?? m.default_hours));
  const [note, setNote] = useState(m.rule?.evidence_note ?? '');
  const [error, setError] = useState<string | null>(null);
  const set = (i: number, patch: Partial<Activity>) =>
    setActivities(activities.map((a, n) => (n === i ? { ...a, ...patch } : a)));
  const save = useMutation({
    mutationFn: () =>
      call('PUT', `/modules/${m.id}/logbook-rules`, {
        activities: activities.map((a) => ({ ...a, key: a.key || null })),
        hours_needed: Number(hours),
        evidence_note: note || null,
      }),
    onSuccess: () => {
      setError(null);
      toast(`Saved the logbook for ${m.code}`);
      void qc.invalidateQueries({ queryKey: ['logbook-rules', qualificationId] });
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });

  return (
    <Card>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-[15px] font-semibold text-ink">
          {m.code} {m.title}
        </h2>
        {m.rule ? <Badge tone="success">Set up</Badge> : <Badge tone="warning">Not set up</Badge>}
      </div>
      <fieldset disabled={!manage} className="grid gap-3">
        <legend className="sr-only">Logbook for {m.code}</legend>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {activities.map((a, i) => (
          <div key={i} className="flex flex-wrap items-end gap-2">
            <Field
              className="min-w-48 flex-1"
              label={`Activity ${i + 1}`}
              value={a.title}
              onChange={(e) => set(i, { title: e.target.value })}
            />
            <label className="mb-2.5 flex items-center gap-2 text-small">
              <input
                type="checkbox"
                className="size-5"
                checked={a.photo}
                onChange={(e) => set(i, { photo: e.target.checked })}
              />
              Needs a photo
            </label>
            {activities.length > 1 ? (
              <Button
                variant="ghost"
                size="sm"
                aria-label={`Remove activity ${i + 1}`}
                onClick={() => setActivities(activities.filter((_, n) => n !== i))}
              >
                <Trash2 className="size-4" />
              </Button>
            ) : null}
          </div>
        ))}
        {manage && activities.length < 40 ? (
          <div>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => setActivities([...activities, { key: '', title: '', photo: false }])}
            >
              Add an activity
            </Button>
          </div>
        ) : null}
        <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
          <Field
            label="Hours needed"
            type="number"
            min={1}
            max={5000}
            value={hours}
            hint={`${m.credits} credits × 10 = ${m.default_hours} h`}
            onChange={(e) => setHours(e.target.value)}
          />
          <Field label="Note for learners (optional)" value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
        {manage ? (
          <div>
            <Button loading={save.isPending} onClick={() => save.mutate()}>
              Save {m.code}
            </Button>
          </div>
        ) : null}
      </fieldset>
    </Card>
  );
}
