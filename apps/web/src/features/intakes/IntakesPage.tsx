import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { Inbox, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, Dialog, EmptyState, Field, PageHeader, Select, Skeleton, Table } from '@/components/ui';
import { ApiError, call } from '@/lib/api';
import { useCan } from '@/lib/session';
import type { CohortRow } from '@/features/cohorts/types';
import { DOCUMENT_TYPES, type DocumentRule } from './documents';
import { day, intakeState, type Intake, type Question } from './types';

/** Intakes: a cohort opened for applications, with a link and QR code to share. */
export function IntakesPage() {
  const can = useCan();
  const [adding, setAdding] = useState(false);
  const [rules, setRules] = useState(false);
  const q = useQuery({ queryKey: ['intakes'], queryFn: () => call<{ data: Intake[] }>('GET', '/intakes') });

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Applications"
        description="Open a cohort for applications and share the link by WhatsApp or a QR poster. Applicants prove their phone with a PIN, their ID number is checked as they type, and you move them from new to shortlisted, waiting list or offered."
        actions={
          can('applications.manage') ? (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setRules(true)}>
                Document rules
              </Button>
              <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
                Open an intake
              </Button>
            </div>
          ) : null
        }
      />
      <Card>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(i) => i.id}
            empty={
              <EmptyState icon={<Inbox className="size-5" />} title="No intakes yet">
                Open one of your cohorts for applications.
              </EmptyState>
            }
            columns={[
              {
                key: 'title',
                header: 'Intake',
                cell: (i) => (
                  <div className="min-w-0">
                    <Link to="/intakes/$id" params={{ id: i.id }} className="font-medium text-ink hover:underline">
                      {i.title}
                    </Link>
                    <div className="text-small text-muted">
                      {[i.qualification, i.cohort].filter(Boolean).join(' · ')}
                    </div>
                  </div>
                ),
              },
              {
                key: 'dates',
                header: 'Applications',
                cell: (i) => (
                  <span className="text-small whitespace-nowrap">
                    {day(i.opens_on)} – {day(i.closes_on)}
                  </span>
                ),
              },
              {
                key: 'counts',
                header: 'Received',
                cell: (i) => {
                  const total = Object.values(i.counts ?? {}).reduce((a, b) => a + b, 0);
                  return (
                    <span className="text-small">
                      <span className="font-medium tabular-nums">{total}</span> for {i.places} places
                      {i.counts?.submitted ? <span className="text-muted"> · {i.counts.submitted} new</span> : null}
                    </span>
                  );
                },
              },
              {
                key: 'status',
                header: 'Status',
                cell: (i) => (
                  <Badge tone={intakeState[i.status].tone} dot>
                    {intakeState[i.status].label}
                  </Badge>
                ),
              },
            ]}
          />
        )}
      </Card>
      {adding ? <IntakeDialog intake={null} onClose={() => setAdding(false)} /> : null}
      {rules ? <RulesDialog onClose={() => setRules(false)} /> : null}
    </>
  );
}

export function IntakeDialog({ intake, onClose }: { intake: Intake | null; onClose: () => void }) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    cohort_id: intake?.cohort_id ?? '',
    title: intake?.title ?? '',
    description: intake?.description ?? '',
    opens_on: intake?.opens_on ?? new Date().toISOString().slice(0, 10),
    closes_on: intake?.closes_on ?? '',
    places: String(intake?.places ?? ''),
    min_age: String(intake?.min_age ?? ''),
    max_age: String(intake?.max_age ?? ''),
  });
  const [questions, setQuestions] = useState<Question[]>(intake?.questions ?? []);
  const [docs, setDocs] = useState<string[]>(intake?.document_types ?? ['id_document', 'highest_qualification']);
  const cohorts = useQuery({
    queryKey: ['cohorts'],
    queryFn: () => call<{ data: CohortRow[] }>('GET', '/cohorts'),
    enabled: !intake,
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const num = (v: string) => (v === '' ? null : Number(v));
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...(intake ? {} : { cohort_id: form.cohort_id }),
        title: form.title,
        description: form.description || null,
        opens_on: form.opens_on,
        closes_on: form.closes_on,
        ...(form.places ? { places: Number(form.places) } : {}),
        min_age: num(form.min_age),
        max_age: num(form.max_age),
        questions,
        document_types: docs,
      };
      return intake
        ? call<{ data: Intake }>('PATCH', `/intakes/${intake.id}`, body)
        : call<{ data: Intake }>('POST', '/intakes', body);
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['intakes'] });
      onClose();
      if (!intake) void navigate({ to: '/intakes/$id', params: { id: r.data.id } });
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const setQ = (i: number, patch: Partial<Question>) =>
    setQuestions(questions.map((q, n) => (n === i ? { ...q, ...patch } : q)));

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={intake ? 'Change intake' : 'Open an intake'}
      description="Applicants see this on the public page."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {!intake ? (
          <Select
            label="Cohort"
            value={form.cohort_id}
            onChange={(e) => {
              const c = cohorts.data?.data.find((x) => x.id === e.target.value);
              setForm({
                ...form,
                cohort_id: e.target.value,
                title: form.title || (c?.name ?? ''),
                places: form.places || String(c?.capacity ?? ''),
              });
            }}
          >
            <option value="">Choose…</option>
            {cohorts.data?.data
              .filter((c) => c.status === 'draft' || c.status === 'open')
              .map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
          </Select>
        ) : null}
        {err('cohort_id') ? <p className="text-small text-danger">{err('cohort_id')}</p> : null}
        <Field label="Title" required value={form.title} onChange={set('title')} error={err('title')} />
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">What applicants should know</span>
          <textarea
            className="min-h-20 rounded-lg border border-line bg-surface p-2"
            value={form.description}
            onChange={set('description')}
          />
        </label>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field
            label="Opens"
            type="date"
            required
            value={form.opens_on}
            onChange={set('opens_on')}
            error={err('opens_on')}
          />
          <Field
            label="Closes"
            type="date"
            required
            value={form.closes_on}
            onChange={set('closes_on')}
            error={err('closes_on')}
          />
          <Field
            label="Places"
            type="number"
            min={1}
            value={form.places}
            onChange={set('places')}
            error={err('places')}
            hint="The cohort's size if empty"
          />
          <Field
            label="Minimum age"
            type="number"
            min={14}
            value={form.min_age}
            onChange={set('min_age')}
            error={err('min_age')}
          />
          <Field
            label="Maximum age"
            type="number"
            min={14}
            value={form.max_age}
            onChange={set('max_age')}
            error={err('max_age')}
          />
        </div>
        <fieldset className="grid gap-1.5 rounded-lg border border-line p-3 text-small">
          <legend className="px-1 font-medium">Documents applicants must add</legend>
          {Object.entries(DOCUMENT_TYPES).map(([type, label]) => (
            <label key={type} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={docs.includes(type)}
                onChange={(e) => setDocs(e.target.checked ? [...docs, type] : docs.filter((d) => d !== type))}
              />
              {label}
            </label>
          ))}
        </fieldset>
        <fieldset className="grid gap-2 rounded-lg border border-line p-3">
          <legend className="px-1 text-small font-medium">Questions (optional)</legend>
          {questions.map((q, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_9rem_auto] sm:items-end">
              <Field
                label={`Question ${i + 1}`}
                value={q.label}
                onChange={(e) => setQ(i, { label: e.target.value })}
                error={err(`questions.${i}.label`)}
              />
              <Select
                label="Answer"
                value={q.type}
                onChange={(e) => setQ(i, { type: e.target.value as Question['type'] })}
              >
                <option value="yesno">Yes or no</option>
                <option value="choice">A choice</option>
                <option value="text">Words</option>
              </Select>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                aria-label={`Remove question ${i + 1}`}
                icon={<Trash2 className="size-4" />}
                onClick={() => setQuestions(questions.filter((_, n) => n !== i))}
              >
                <span className="sr-only">Remove</span>
              </Button>
              {q.type === 'choice' ? (
                <div className="sm:col-span-3">
                  <Field
                    label="Choices, separated by commas"
                    value={(q.options ?? []).join(', ')}
                    onChange={(e) =>
                      setQ(i, {
                        options: e.target.value
                          .split(',')
                          .map((s) => s.trim())
                          .filter(Boolean),
                      })
                    }
                    error={err(`questions.${i}.options`)}
                  />
                </div>
              ) : null}
              <label className="flex items-center gap-2 text-small sm:col-span-3">
                <input
                  type="checkbox"
                  checked={q.required ?? false}
                  onChange={(e) => setQ(i, { required: e.target.checked })}
                />{' '}
                Must be answered
              </label>
            </div>
          ))}
          {questions.length < 15 ? (
            <Button
              type="button"
              size="sm"
              variant="secondary"
              icon={<Plus className="size-4" />}
              onClick={() =>
                setQuestions([
                  ...questions,
                  { key: `q${Date.now().toString(36)}`, label: '', type: 'yesno', required: false },
                ])
              }
            >
              Add a question
            </Button>
          ) : null}
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            {intake ? 'Save' : 'Continue'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/** How old a certified copy may be, per document type: 90 days unless the provider chooses otherwise. */
function RulesDialog({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['enrolment-settings'],
    queryFn: () => call<{ data: DocumentRule[] }>('GET', '/enrolment-settings').then((r) => r.data),
  });
  const [draft, setDraft] = useState<Record<string, { certified: boolean; max_age_days: number | null }>>({});
  const value = (r: DocumentRule) => draft[r.type] ?? { certified: r.certified, max_age_days: r.max_age_days };
  const save = useMutation({
    mutationFn: () => call('PATCH', '/enrolment-settings', { document_rules: draft }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['enrolment-settings'] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Document rules"
      description="Most institutions accept copies certified in the last 3 months; government accepts 6 months. Choose per document."
    >
      <div className="grid gap-3">
        {(q.data ?? []).map((r) => (
          <div key={r.type} className="grid items-end gap-2 sm:grid-cols-[1fr_8rem_8rem]">
            <span className="text-small font-medium text-ink">{r.label}</span>
            <label className="flex items-center gap-2 text-small">
              <input
                type="checkbox"
                checked={value(r).certified}
                onChange={(e) => setDraft({ ...draft, [r.type]: { ...value(r), certified: e.target.checked } })}
              />
              Certified copy
            </label>
            <Field
              label="Days old, at most"
              type="number"
              min={7}
              max={730}
              disabled={!value(r).certified}
              value={value(r).max_age_days ?? ''}
              onChange={(e) =>
                setDraft({
                  ...draft,
                  [r.type]: { ...value(r), max_age_days: e.target.value === '' ? null : Number(e.target.value) },
                })
              }
            />
          </div>
        ))}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={!Object.keys(draft).length} onClick={() => save.mutate()}>
            Save
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
