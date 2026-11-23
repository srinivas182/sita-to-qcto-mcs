import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Dialog, Field, PageHeader, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';
import { type Instrument, type ModuleResults, type ResultRow, methodLabel, stateBadge } from './types';

interface Detail {
  enrolment_id: string;
  name: string | null;
  modules: ModuleResults[];
  instruments: (Instrument & { module_id: string })[];
  history: ResultRow[];
  max_attempts: number;
}

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Johannesburg' });

/** One learner's modules and attempts; registered assessors record decisions here. */
export function LearnerResultsPage() {
  const { id, enrolment } = useParams({ from: '/app/cohorts/$id/results/$enrolment' });
  const can = useCan();
  const [deciding, setDeciding] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['learner-results', enrolment],
    queryFn: () => call<{ data: Detail }>('GET', `/cohorts/${id}/results/${enrolment}`).then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const d = q.data;
  const titles = new Map(d.instruments.map((i) => [i.id, i.title]));
  return (
    <>
      <Link
        to="/cohorts/$id/results"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Results
      </Link>
      <PageHeader title={d.name ?? 'Learner'} description={`Up to ${d.max_attempts} attempts per assessment.`} />
      <div className="grid gap-4">
        {d.modules.map((m) => (
          <Card key={m.module_id}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-[15px] font-semibold text-ink">
                {m.code} {m.title}
              </h2>
              <Badge tone={stateBadge[m.state].tone}>{stateBadge[m.state].label}</Badge>
            </div>
            {m.instruments.length === 0 ? (
              <p className="text-small text-muted">No assessment set for this module.</p>
            ) : (
              <ul className="divide-y divide-line">
                {m.instruments.map((i) => {
                  const done = i.latest?.outcome === 'competent';
                  const out = i.attempts >= d.max_attempts && !done;
                  return (
                    <li key={i.instrument_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                      <div>
                        <p className="text-ink">
                          {i.title} <span className="text-muted">· {methodLabel[i.method]}</span>
                        </p>
                        <p className="text-small text-muted">
                          {i.latest
                            ? `Attempt ${i.latest.attempt}: ${i.latest.outcome === 'competent' ? 'Competent' : 'Not yet competent'}${i.latest.score !== null ? ` (${i.latest.score}%)` : ''} on ${shortDate(i.latest.assessed_on)}`
                            : 'Not assessed yet'}
                        </p>
                      </div>
                      {can('assessment.conduct') && !done && !out ? (
                        <Button size="sm" onClick={() => setDeciding(i.instrument_id)}>
                          {i.attempts ? 'Reassess' : 'Assess'}
                          <span className="sr-only"> {i.title}</span>
                        </Button>
                      ) : out ? (
                        <Badge tone="danger">All attempts used</Badge>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        ))}
        <Card>
          <CardHeader title="All decisions" />
          {d.history.length === 0 ? (
            <p className="text-small text-muted">None yet.</p>
          ) : (
            <ul className="divide-y divide-line">
              {d.history.map((r) => (
                <li key={r.id} className="py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-medium text-ink">
                      {titles.get(r.instrument_id)} · attempt {r.attempt} · {shortDate(r.assessed_on)}
                    </p>
                    <div className="flex items-center gap-2">
                      {r.intact === false ? (
                        <Badge tone="danger">
                          <ShieldAlert className="size-3.5" aria-hidden /> Changed after the decision
                        </Badge>
                      ) : null}
                      <Badge tone={r.outcome === 'competent' ? 'success' : 'danger'}>
                        {r.outcome === 'competent' ? 'Competent' : 'Not yet competent'}
                      </Badge>
                    </div>
                  </div>
                  {r.feedback ? <p className="text-small text-ink">{r.feedback}</p> : null}
                  {r.remediation ? <p className="text-small text-muted">Remediation: {r.remediation}</p> : null}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      {deciding ? (
        <DecisionDialog
          enrolmentId={d.enrolment_id}
          instrument={d.instruments.find((i) => i.id === deciding)!}
          onClose={() => setDeciding(null)}
        />
      ) : null}
    </>
  );
}

function DecisionDialog({
  enrolmentId,
  instrument,
  onClose,
}: {
  enrolmentId: string;
  instrument: Instrument;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const [met, setMet] = useState<Record<string, boolean>>({});
  const [comments, setComments] = useState<Record<string, string>>({});
  const [score, setScore] = useState('');
  const [date, setDate] = useState(today());
  const [feedback, setFeedback] = useState('');
  const [remediation, setRemediation] = useState('');
  const [files, setFiles] = useState<{ id: string; name: string }[]>([]);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const test = instrument.method === 'test';
  const outcome = useMemo(() => {
    const all = instrument.criteria.every((c) => met[c.key]);
    return all && (!test || (score !== '' && Number(score) >= (instrument.pass_mark ?? 0))) ? 'competent' : 'nyc';
  }, [met, score, instrument, test]);
  const save = useMutation({
    mutationFn: () =>
      call('POST', '/assessment-results', {
        enrolment_id: enrolmentId,
        instrument_id: instrument.id,
        outcome,
        score: test ? Number(score) : null,
        criteria: instrument.criteria.map((c) => ({ key: c.key, met: !!met[c.key], comment: comments[c.key] || null })),
        file_ids: files.map((f) => f.id),
        feedback: feedback || null,
        remediation: remediation || null,
        assessed_on: date,
      }),
    onSuccess: () => {
      toast(outcome === 'competent' ? 'Recorded: Competent' : 'Recorded: Not yet competent');
      void qc.invalidateQueries({ queryKey: ['learner-results', enrolmentId] });
      void qc.invalidateQueries({ queryKey: ['cohort-results'] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const add = async (list: FileList | null) => {
    if (!list?.length) return;
    setUploading(true);
    try {
      for (const f of Array.from(list)) {
        const up = await uploadFile('assessment_evidence', f);
        setFiles((x) => [...x, { id: up.id, name: f.name }]);
      }
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setUploading(false);
    }
  };
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={instrument.title}
      description={instrument.instructions ?? undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={uploading} onClick={() => save.mutate()}>
            Record {outcome === 'competent' ? 'Competent' : 'Not yet competent'}
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label="Date assessed" type="date" value={date} max={today()} onChange={(e) => setDate(e.target.value)} />
        {test ? (
          <Field
            label={`Score (%) — pass mark ${instrument.pass_mark ?? 0}%`}
            type="number"
            min={0}
            max={100}
            value={score}
            onChange={(e) => setScore(e.target.value)}
          />
        ) : null}
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-small font-medium text-ink">Criteria</legend>
          {instrument.criteria.map((c) => (
            <div key={c.key} className="grid gap-1 rounded-lg border border-line p-2">
              <label className="flex items-start gap-2 text-small">
                <input
                  type="checkbox"
                  className="mt-0.5 size-5"
                  checked={!!met[c.key]}
                  onChange={(e) => setMet({ ...met, [c.key]: e.target.checked })}
                />
                {c.text}
              </label>
              <Field
                label={`Comment on “${c.text}” (optional)`}
                value={comments[c.key] ?? ''}
                onChange={(e) => setComments({ ...comments, [c.key]: e.target.value })}
              />
            </div>
          ))}
        </fieldset>
        <p className="text-small text-ink">
          Outcome:{' '}
          <Badge tone={outcome === 'competent' ? 'success' : 'danger'}>
            {outcome === 'competent' ? 'Competent' : 'Not yet competent'}
          </Badge>
        </p>
        <div className="grid gap-1.5">
          <label htmlFor="feedback" className="text-small font-medium text-ink">
            Feedback to the learner{outcome === 'nyc' ? ' (needed)' : ''}
          </label>
          <textarea
            id="feedback"
            className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
            value={feedback}
            onChange={(e) => setFeedback(e.target.value)}
          />
        </div>
        {outcome === 'nyc' ? (
          <div className="grid gap-1.5">
            <label htmlFor="remediation" className="text-small font-medium text-ink">
              Remediation before the next attempt
            </label>
            <textarea
              id="remediation"
              className="min-h-16 rounded-lg border border-line bg-surface px-3 py-2 text-body"
              value={remediation}
              onChange={(e) => setRemediation(e.target.value)}
            />
          </div>
        ) : null}
        <div className="grid gap-1.5">
          <label htmlFor="evidence" className="text-small font-medium text-ink">
            Evidence (photos, video, PDF)
          </label>
          <input
            id="evidence"
            type="file"
            multiple
            accept="image/jpeg,image/png,application/pdf,video/mp4,video/quicktime"
            onChange={(e) => void add(e.target.files)}
          />
          {files.length ? <p className="text-small text-muted">{files.map((f) => f.name).join(', ')}</p> : null}
        </div>
      </div>
    </Dialog>
  );
}
