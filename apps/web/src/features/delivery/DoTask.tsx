import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Badge, Button, Dialog, Field } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { uploadFile } from '@/lib/upload';

export interface LearnerTask {
  id: string;
  module_id: string | null;
  title: string;
  instructions: string | null;
  evidence: boolean;
  evidence_hint: string | null;
  pass_pct: number;
  attempts_allowed: number;
  out_of: number;
  questions: { id: string; type: 'single' | 'multi' | 'short'; text: string; options?: string[]; marks: number }[];
  attempts: {
    id: string;
    number: number;
    status: 'submitted' | 'passed' | 'not_yet';
    score_pct: number | null;
    feedback: string | null;
  }[];
}

export const taskState = {
  passed: ['success', 'Passed'],
  not_yet: ['danger', 'Not yet'],
  submitted: ['warning', 'Waiting for marking'],
} as const;

/** A learner's tasks for a course, with the latest result. */
export function CourseTasks({ enrolmentId }: { enrolmentId: string }) {
  const [doing, setDoing] = useState<LearnerTask | null>(null);
  const q = useQuery({
    queryKey: ['me', 'tasks', enrolmentId],
    queryFn: () => call<{ data: LearnerTask[] }>('GET', `/me/courses/${enrolmentId}/tasks`).then((r) => r.data),
  });
  if (!q.data?.length) return null;
  return (
    <section className="mt-4">
      <h3 className="mb-1 text-small font-semibold text-muted">Tasks</h3>
      <ul className="divide-y divide-line">
        {q.data.map((t) => {
          const last = t.attempts[t.attempts.length - 1];
          const passed = t.attempts.some((a) => a.status === 'passed');
          const canTry = !passed && last?.status !== 'submitted' && t.attempts.length < t.attempts_allowed;
          return (
            <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <span className="block text-ink">{t.title}</span>
                {last?.feedback ? <span className="block text-small text-muted">Feedback: {last.feedback}</span> : null}
              </span>
              <span className="flex items-center gap-2">
                {last ? (
                  <Badge tone={taskState[last.status][0]}>
                    {taskState[last.status][1]}
                    {last.score_pct !== null ? ` ${last.score_pct}%` : ''}
                  </Badge>
                ) : null}
                {canTry ? (
                  <Button size="sm" onClick={() => setDoing(t)}>
                    {t.attempts.length ? 'Try again' : 'Start'}
                    <span className="sr-only"> {t.title}</span>
                  </Button>
                ) : null}
              </span>
            </li>
          );
        })}
      </ul>
      {doing ? <TaskDialog task={doing} enrolmentId={enrolmentId} onClose={() => setDoing(null)} /> : null}
    </section>
  );
}

function TaskDialog({ task, enrolmentId, onClose }: { task: LearnerTask; enrolmentId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [answers, setAnswers] = useState<Record<string, string | number[]>>({});
  const [files, setFiles] = useState<{ id: string; name: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const send = useMutation({
    mutationFn: () => call('POST', `/me/tasks/${task.id}/attempts`, { answers, file_ids: files.map((f) => f.id) }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['me', 'tasks', enrolmentId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const add = async (f: File | undefined) => {
    if (!f) return;
    setBusy(true);
    try {
      const up = await uploadFile('task_evidence', f);
      setFiles((x) => [...x, { id: up.id, name: f.name }]);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={task.title}
      description={task.instructions ?? `Pass mark ${task.pass_pct}%.`}
    >
      <div className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {task.questions.map((q, i) => (
          <fieldset key={q.id} className="grid gap-1.5">
            <legend className="mb-1 text-small font-medium text-ink">
              {i + 1}. {q.text}
            </legend>
            {q.type === 'short' ? (
              <Field
                label="Your answer"
                value={String(answers[q.id] ?? '')}
                onChange={(e) => setAnswers({ ...answers, [q.id]: e.target.value })}
              />
            ) : (
              (q.options ?? []).map((o, n) => {
                const picked = (answers[q.id] as number[] | undefined) ?? [];
                return (
                  <label key={n} className="flex items-center gap-2 text-small">
                    <input
                      type={q.type === 'single' ? 'radio' : 'checkbox'}
                      className="size-5"
                      name={q.id}
                      checked={picked.includes(n)}
                      onChange={(e) =>
                        setAnswers({
                          ...answers,
                          [q.id]:
                            q.type === 'single'
                              ? [n]
                              : e.target.checked
                                ? [...picked, n]
                                : picked.filter((x) => x !== n),
                        })
                      }
                    />
                    {o}
                  </label>
                );
              })
            )}
          </fieldset>
        ))}
        {task.evidence ? (
          <div className="grid gap-1.5">
            <label htmlFor="evidence" className="text-small font-medium text-ink">
              Evidence{task.evidence_hint ? `: ${task.evidence_hint}` : ''}
            </label>
            <input
              id="evidence"
              type="file"
              accept="image/*,video/mp4,video/quicktime,application/pdf"
              className="text-small"
              onChange={(e) => void add(e.target.files?.[0])}
            />
            <p className="text-small text-muted">
              {busy
                ? 'Uploading…'
                : files.length
                  ? files.map((f) => f.name).join(', ')
                  : 'Add photos, a short video or a PDF.'}
            </p>
          </div>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={send.isPending} disabled={busy} onClick={() => send.mutate()}>
            Hand in
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
