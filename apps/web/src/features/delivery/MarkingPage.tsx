import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, Dialog, EmptyState, Field, PageHeader, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import type { Question } from './TasksPage';

interface Waiting {
  id: string;
  task: string | null;
  learner: string | null;
  cohort: string | null;
  number: number;
  submitted_at: string;
  offline: boolean;
}
interface Detail {
  id: string;
  answers: Record<string, string | number[]>;
  task: {
    title: string;
    instructions: string | null;
    questions: Question[];
    evidence: boolean;
    evidence_hint: string | null;
    pass_pct: number;
    out_of: number;
  };
  learner: string | null;
  files: { id: string; original_name: string; mime: string; size: number }[];
}

/** Attempts waiting for marking: short answers and evidence, with feedback for the learner. */
export function MarkingPage() {
  const [open, setOpen] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['marking'],
    queryFn: () => call<{ data: Waiting[] }>('GET', '/task-attempts').then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  return (
    <>
      <PageHeader
        title="Marking"
        description="Formative tasks waiting for you, oldest first. Multiple-choice answers are already marked."
      />
      <Card>
        {q.data.length === 0 ? (
          <EmptyState title="Nothing to mark">New attempts appear here.</EmptyState>
        ) : (
          <ul className="divide-y divide-line">
            {q.data.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <span>
                  <span className="block font-medium text-ink">
                    {a.learner} · {a.task}
                  </span>
                  <span className="block text-small text-muted">
                    {a.cohort} · attempt {a.number} · {a.submitted_at.slice(0, 10)}
                    {a.offline ? ' · sent from the phone later' : ''}
                  </span>
                </span>
                <Button size="sm" onClick={() => setOpen(a.id)}>
                  Mark<span className="sr-only"> {a.learner}</span>
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {open ? <MarkDialog id={open} onClose={() => setOpen(null)} /> : null}
    </>
  );
}

function MarkDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({
    queryKey: ['attempt', id],
    queryFn: () => call<{ data: Detail }>('GET', `/task-attempts/${id}`).then((r) => r.data),
  });
  const [marks, setMarks] = useState<Record<string, string>>({});
  const [feedback, setFeedback] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () =>
      call('POST', `/task-attempts/${id}/mark`, {
        marks: Object.fromEntries(Object.entries(marks).map(([k, v]) => [k, Number(v)])),
        feedback,
      }),
    onSuccess: () => {
      toast('Marked; the learner is told');
      void qc.invalidateQueries({ queryKey: ['marking'] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { feedback: [errorMessage(e)] }),
  });
  const openFile = (fileId: string) =>
    void call<{ url: string }>('GET', `/files/${fileId}/download`)
      .then((r) => window.open(r.url, '_blank', 'noopener'))
      .catch((e: unknown) => toast(errorMessage(e), 'error'));
  const d = q.data;

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={d ? `${d.learner}: ${d.task.title}` : 'Mark'}>
      {!d ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="grid gap-3">
          {d.task.questions.map((qq, i) => {
            const given = d.answers[qq.id ?? ''];
            return (
              <div key={qq.id} className="rounded-lg border border-line p-3 text-small">
                <p className="font-medium text-ink">
                  {i + 1}. {qq.text} <span className="text-muted">({qq.marks} marks)</span>
                </p>
                {qq.type === 'short' ? (
                  <>
                    <p className="mt-1 whitespace-pre-wrap text-ink">{String(given ?? '')}</p>
                    {qq.guide ? <p className="mt-1 text-muted">Guide: {qq.guide}</p> : null}
                    <Field
                      className="mt-2 max-w-[10rem]"
                      label={`Marks out of ${qq.marks}`}
                      type="number"
                      min={0}
                      max={qq.marks}
                      value={marks[qq.id!] ?? ''}
                      onChange={(e) => setMarks({ ...marks, [qq.id!]: e.target.value })}
                      error={errors[`marks.${qq.id}`]?.[0]}
                    />
                  </>
                ) : (
                  <p className="mt-1 text-muted">
                    Answered: {(Array.isArray(given) ? given : []).map((n) => qq.options?.[n]).join(', ')} ·{' '}
                    {JSON.stringify([...(Array.isArray(given) ? given : [])].sort()) ===
                    JSON.stringify([...(qq.correct ?? [])].sort())
                      ? 'right'
                      : 'wrong'}{' '}
                    (marked automatically)
                  </p>
                )}
              </div>
            );
          })}
          {d.task.evidence ? (
            <div className="rounded-lg border border-line p-3 text-small">
              <p className="font-medium text-ink">Evidence {d.task.evidence_hint ? `: ${d.task.evidence_hint}` : ''}</p>
              <ul className="mt-1 grid gap-1">
                {d.files.map((f) => (
                  <li key={f.id}>
                    <button
                      type="button"
                      className="inline-flex items-center gap-1 text-primary-700 underline"
                      onClick={() => openFile(f.id)}
                    >
                      {f.original_name} <ExternalLink className="size-3.5" aria-hidden />
                    </button>
                  </li>
                ))}
              </ul>
              <Field
                className="mt-2 max-w-[10rem]"
                label="Marks out of 10"
                type="number"
                min={0}
                max={10}
                value={marks.evidence ?? ''}
                onChange={(e) => setMarks({ ...marks, evidence: e.target.value })}
                error={errors['marks.evidence']?.[0]}
              />
            </div>
          ) : null}
          <Field
            label="Feedback for the learner"
            value={feedback}
            onChange={(e) => setFeedback(e.target.value)}
            error={errors.feedback?.[0]}
          />
          <p className="text-small text-muted">
            Pass mark {d.task.pass_pct}% of {d.task.out_of}.
          </p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button loading={save.isPending} disabled={feedback.trim().length < 3} onClick={() => save.mutate()}>
              Save marks
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
