import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
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
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';

export interface Question {
  id?: string;
  type: 'single' | 'multi' | 'short';
  text: string;
  options?: string[];
  correct?: number[];
  marks: number;
  guide?: string | null;
}
interface TaskRow {
  id: string;
  module_id: string | null;
  title: string;
  instructions: string | null;
  questions: Question[];
  evidence: boolean;
  evidence_hint: string | null;
  pass_pct: number;
  attempts: number;
  status: 'draft' | 'published';
  out_of: number;
  answered: number;
}
interface Module {
  id: string;
  code: string;
  title: string;
}

/** Formative tasks for a qualification: quizzes marked at once, short answers and evidence marked by a facilitator. */
export function TasksPage() {
  const { id } = useParams({ from: '/app/programmes/$id/tasks' });
  const qc = useQueryClient();
  const toast = useToast();
  const [editing, setEditing] = useState<TaskRow | 'new' | null>(null);
  const q = useQuery({
    queryKey: ['tasks', id],
    queryFn: () => call<{ data: TaskRow[]; modules: Module[] }>('GET', '/tasks', undefined, { qualification_id: id }),
  });
  const publish = useMutation({
    mutationFn: (t: TaskRow) => call('POST', `/tasks/${t.id}/publish`, { published: t.status !== 'published' }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['tasks', id] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const copy = useMutation({
    mutationFn: (t: TaskRow) => call('POST', `/tasks/${t.id}/copy`),
    onSuccess: () => {
      toast('Copy made as a draft');
      void qc.invalidateQueries({ queryKey: ['tasks', id] });
    },
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const mods = new Map(q.data.modules.map((m) => [m.id, m.code]));

  return (
    <>
      <Link to="/programmes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Programmes
      </Link>
      <PageHeader
        title="Formative tasks"
        description="Practice and evidence before the summative assessment. Multiple-choice questions are marked at once; short answers and evidence (photos, video, a PDF) by the facilitator, with feedback."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Add a task
          </Button>
        }
      />
      <Card>
        {q.data.data.length === 0 ? (
          <EmptyState title="No tasks yet">Add a quiz or ask learners for evidence of practical work.</EmptyState>
        ) : (
          <ul className="divide-y divide-line">
            {q.data.data.map((t) => (
              <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                <button type="button" className="min-w-0 text-left" onClick={() => setEditing(t)}>
                  <span className="block font-medium text-ink">{t.title}</span>
                  <span className="block text-small text-muted">
                    {[
                      t.module_id ? mods.get(t.module_id) : 'Whole course',
                      `${t.questions.length} questions`,
                      t.evidence ? 'evidence' : null,
                      `${t.out_of} marks`,
                      `pass ${t.pass_pct}%`,
                      `${t.answered} attempts`,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                </button>
                <span className="flex items-center gap-2">
                  <Badge tone={t.status === 'published' ? 'success' : 'neutral'} dot>
                    {t.status === 'published' ? 'Published' : 'Draft'}
                  </Badge>
                  {t.answered ? (
                    <Button size="sm" variant="ghost" onClick={() => copy.mutate(t)}>
                      Copy<span className="sr-only"> {t.title}</span>
                    </Button>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => publish.mutate(t)}>
                    {t.status === 'published' ? 'Unpublish' : 'Publish'}
                    <span className="sr-only"> {t.title}</span>
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {editing ? (
        <TaskDialog
          qualificationId={id}
          modules={q.data.modules}
          task={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </>
  );
}

const blank = (): Question => ({ type: 'single', text: '', options: ['', ''], correct: [0], marks: 1 });

function TaskDialog({
  qualificationId,
  modules,
  task,
  onClose,
}: {
  qualificationId: string;
  modules: Module[];
  task: TaskRow | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const locked = (task?.answered ?? 0) > 0;
  const [form, setForm] = useState({
    title: task?.title ?? '',
    module_id: task?.module_id ?? '',
    instructions: task?.instructions ?? '',
    evidence: task?.evidence ?? false,
    evidence_hint: task?.evidence_hint ?? '',
    pass_pct: String(task?.pass_pct ?? 70),
    attempts: String(task?.attempts ?? 3),
  });
  const [questions, setQuestions] = useState<Question[]>(task?.questions ?? [blank()]);
  const [error, setError] = useState<string | null>(null);
  const setQ = (i: number, patch: Partial<Question>) =>
    setQuestions(questions.map((q, n) => (n === i ? { ...q, ...patch } : q)));
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        module_id: form.module_id || null,
        instructions: form.instructions || null,
        evidence_hint: form.evidence_hint || null,
        pass_pct: Number(form.pass_pct),
        attempts: Number(form.attempts),
        ...(locked ? {} : { questions }),
      };
      return task
        ? call('PATCH', `/tasks/${task.id}`, body)
        : call('POST', '/tasks', { ...body, qualification_id: qualificationId });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['tasks', qualificationId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={task ? 'Change task' : 'Add a task'}>
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <div className="grid gap-3 sm:grid-cols-3">
          <Select
            label="Module"
            value={form.module_id}
            onChange={(e) => setForm({ ...form, module_id: e.target.value })}
          >
            <option value="">Whole course</option>
            {modules.map((m) => (
              <option key={m.id} value={m.id}>
                {m.code} {m.title}
              </option>
            ))}
          </Select>
          <Field
            label="Pass mark (%)"
            type="number"
            min={1}
            max={100}
            value={form.pass_pct}
            onChange={(e) => setForm({ ...form, pass_pct: e.target.value })}
          />
          <Field
            label="Attempts"
            type="number"
            min={1}
            max={10}
            value={form.attempts}
            onChange={(e) => setForm({ ...form, attempts: e.target.value })}
          />
        </div>
        <Field
          label="Instructions (optional)"
          value={form.instructions}
          onChange={(e) => setForm({ ...form, instructions: e.target.value })}
        />
        <label className="flex items-center gap-2 text-small">
          <input
            type="checkbox"
            className="size-5"
            checked={form.evidence}
            onChange={(e) => setForm({ ...form, evidence: e.target.checked })}
          />
          Ask for evidence (photos, video or a PDF), marked out of 10
        </label>
        {form.evidence ? (
          <Field
            label="What evidence?"
            value={form.evidence_hint}
            onChange={(e) => setForm({ ...form, evidence_hint: e.target.value })}
          />
        ) : null}
        {locked ? (
          <Alert tone="info">
            Learners have answered this task, so its questions can't change. Make a copy to change them.
          </Alert>
        ) : null}
        {questions.map((q, i) => (
          <fieldset key={i} disabled={locked} className="grid gap-2 rounded-lg border border-line p-3">
            <legend className="px-1 text-small font-semibold text-ink">Question {i + 1}</legend>
            <div className="grid gap-2 sm:grid-cols-[1fr_9rem_6rem]">
              <Field label="Question" value={q.text} onChange={(e) => setQ(i, { text: e.target.value })} />
              <Select
                label="Answer type"
                value={q.type}
                onChange={(e) => {
                  const type = e.target.value as Question['type'];
                  setQ(
                    i,
                    type === 'short'
                      ? { type, options: undefined, correct: undefined }
                      : { type, options: q.options ?? ['', ''], correct: [0] },
                  );
                }}
              >
                <option value="single">One answer</option>
                <option value="multi">Several answers</option>
                <option value="short">Written answer</option>
              </Select>
              <Field
                label="Marks"
                type="number"
                min={1}
                max={20}
                value={q.marks}
                onChange={(e) => setQ(i, { marks: Number(e.target.value) })}
              />
            </div>
            {q.type === 'short' ? (
              <Field
                label="Marking guide (only staff see it)"
                value={q.guide ?? ''}
                onChange={(e) => setQ(i, { guide: e.target.value })}
              />
            ) : (
              <div className="grid gap-2">
                {(q.options ?? []).map((o, n) => (
                  <div key={n} className="flex items-end gap-2">
                    <label className="mb-2.5 flex items-center gap-1 text-small">
                      <input
                        type={q.type === 'single' ? 'radio' : 'checkbox'}
                        className="size-5"
                        name={`correct-${i}`}
                        checked={(q.correct ?? []).includes(n)}
                        onChange={(e) =>
                          setQ(i, {
                            correct:
                              q.type === 'single'
                                ? [n]
                                : e.target.checked
                                  ? [...(q.correct ?? []), n]
                                  : (q.correct ?? []).filter((x) => x !== n),
                          })
                        }
                      />
                      <span className="sr-only">Option {n + 1} is right</span>
                    </label>
                    <Field
                      className="flex-1"
                      label={`Option ${n + 1}`}
                      value={o}
                      onChange={(e) =>
                        setQ(i, { options: (q.options ?? []).map((x, k) => (k === n ? e.target.value : x)) })
                      }
                    />
                  </div>
                ))}
                {(q.options ?? []).length < 8 ? (
                  <Button size="sm" variant="ghost" onClick={() => setQ(i, { options: [...(q.options ?? []), ''] })}>
                    Add an option
                  </Button>
                ) : null}
              </div>
            )}
            {questions.length > 1 ? (
              <Button
                size="sm"
                variant="ghost"
                icon={<Trash2 className="size-4" />}
                onClick={() => setQuestions(questions.filter((_, n) => n !== i))}
              >
                Remove question {i + 1}
              </Button>
            ) : null}
          </fieldset>
        ))}
        {!locked ? (
          <Button
            variant="secondary"
            icon={<Plus className="size-4" />}
            onClick={() => setQuestions([...questions, blank()])}
          >
            Add a question
          </Button>
        ) : null}
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
