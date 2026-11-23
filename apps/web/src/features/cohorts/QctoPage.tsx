import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, Download, Send, XCircle } from 'lucide-react';
import { useState } from 'react';
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
import { provinces } from '@/features/apply/types';
import { CITIZEN, LANGUAGES, NATIONALITIES, TITLES, WORK_STATUS } from '@/features/people/qcto';
import { shortDate } from './types';

interface Problem {
  code?: string;
  column: string;
  field: string;
  problem: string;
  severity?: 'error' | 'warning';
  fix?: 'profile' | 'identity' | 'consent' | 'centre' | 'qualification' | 'cohort' | 'statement' | 'eisa';
}
interface Row {
  enrolment_id: string;
  user_id: string;
  name: string;
  enrolled_on: string;
  sent: boolean;
  columns: Record<string, string>;
  errors: Problem[];
  warnings: Problem[];
}
interface QctoList {
  cohort: {
    id: string;
    name: string;
    starts_on: string;
    qualification: string;
    saqa_id: string | null;
    centre: string;
    sdp_code: string | null;
  };
  due: { due_on: string; days_left: number; working_days: boolean; days: number };
  rows: Row[];
  ready: number;
  total: number;
  sent: Submission[];
  unsent: number;
  rules?: { version: string; source: string };
  errors?: number;
  warnings?: number;
}

interface Submission {
  id: string;
  sent_on: string;
  rows: number;
  note: string | null;
  response_loaded_at?: string | null;
  accepted?: number;
  rejected?: number;
  unmatched?: number;
  learners?: {
    user_id: string;
    name: string;
    outcome_id: string | null;
    status: 'accepted' | 'rejected' | null;
    reason: string | null;
    resolved: boolean;
  }[];
}

/** Where a problem is fixed, for problems outside the learner's own details. */
function FixLink({ p, cohortId }: { p: Problem; cohortId: string }) {
  const cls = 'ml-1 text-primary-700 hover:underline';
  switch (p.fix) {
    case 'centre':
      return (
        <Link to="/centres" className={cls}>
          Fix on Centres
        </Link>
      );
    case 'qualification':
      return (
        <Link to="/programmes" className={cls}>
          Fix on Programmes
        </Link>
      );
    case 'cohort':
      return (
        <Link to="/cohorts/$id" params={{ id: cohortId }} className={cls}>
          Fix on the cohort
        </Link>
      );
    case 'statement':
      return (
        <Link to="/cohorts/$id/statements" params={{ id: cohortId }} className={cls}>
          Statements of Results
        </Link>
      );
    default:
      return null;
  }
}

/**
 * The QCTO learner enrolment list for a cohort: what is missing per learner, the countdown
 * to the due date, the file to email to the QCTO, and a record of each time it was sent.
 */
export function QctoPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/qcto' });
  const can = useCan();
  const manage = can('applications.manage');
  const toast = useToast();
  const [fixing, setFixing] = useState<Row | null>(null);
  const [sending, setSending] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [confirming, setConfirming] = useState<boolean | null>(null);
  const [replying, setReplying] = useState<Submission | null>(null);
  const q = useQuery({
    queryKey: ['qcto', id],
    queryFn: () => call<{ data: QctoList }>('GET', `/cohorts/${id}/qcto-enrolment`).then((r) => r.data),
  });

  const download = async (onlyUnsent: boolean) => {
    setDownloading(true);
    try {
      const res = await fetch(
        `/v1/cohorts/${id}/qcto-enrolment/workbook?acknowledge=1${onlyUnsent ? '&only_unsent=1' : ''}`,
        {
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        },
      );
      if (res.status === 422) {
        const body = (await res.json().catch(() => null)) as { errors?: Record<string, string[]> } | null;
        throw new Error(Object.values(body?.errors ?? {})[0]?.[0] ?? 'The file has errors the QCTO would reject.');
      }
      if (!res.ok) throw new Error('The file could not be made. Try again.');
      const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'LEISA.xlsx';
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = name;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setDownloading(false);
    }
  };

  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const l = q.data;
  const d = l.due;
  const dueTone = l.unsent === 0 ? 'success' : d.days_left < 0 ? 'danger' : d.days_left <= 7 ? 'warning' : 'info';
  const dueText =
    l.unsent === 0
      ? 'Every enrolled learner has been sent to the QCTO.'
      : d.days_left < 0
        ? `Overdue by ${-d.days_left} ${-d.days_left === 1 ? 'day' : 'days'} (was due ${shortDate(d.due_on)}).`
        : d.days_left === 0
          ? 'Due today.'
          : `Due ${shortDate(d.due_on)}: ${d.days_left} ${d.days_left === 1 ? 'day' : 'days'} left.`;

  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> {l.cohort.name}
      </Link>
      <PageHeader
        eyebrow={`${l.cohort.qualification} · SAQA ${l.cohort.saqa_id ?? '—'} · ${l.cohort.centre}`}
        title="QCTO learner enrolment"
        description={`Email the file to learnerenrolments@qcto.org.za within ${d.days} ${d.working_days ? 'working days' : 'days'} of the start of training (${shortDate(l.cohort.starts_on)}). The QCTO does not fix data for providers, so every learner should be complete first.`}
        actions={
          manage && l.total > 0 ? (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                icon={<Download className="size-4" />}
                loading={downloading}
                disabled={(l.errors ?? 0) > 0}
                onClick={() =>
                  (l.warnings ?? 0) > 0 ? setConfirming(l.sent.length > 0) : void download(l.sent.length > 0)
                }
              >
                {l.sent.length > 0 ? 'Download new learners' : 'Download the file'}
              </Button>
              <Button icon={<Send className="size-4" />} disabled={l.unsent === 0} onClick={() => setSending(true)}>
                Mark as sent
              </Button>
            </div>
          ) : null
        }
      />
      <div className="mb-4">
        <Alert tone={dueTone}>
          {dueText} {l.total > 0 ? `${l.ready} of ${l.total} learners complete.` : 'No learners are enrolled yet.'}
        </Alert>
        {(l.errors ?? 0) > 0 ? (
          <div className="mt-2">
            <Alert tone="danger">
              {l.errors} {l.errors === 1 ? 'error' : 'errors'} the QCTO would reject. The file can be downloaded once
              they are fixed.
            </Alert>
          </div>
        ) : null}
        {l.rules ? (
          <p className="mt-2 text-small text-muted">
            Checked against the {l.rules.source} (rules {l.rules.version}).
          </p>
        ) : null}
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <Card>
          <CardHeader
            title="Learners"
            subtitle="Columns A to AJ of the QCTO file. Optional equity details the learner did not give are sent as Unknown."
          />
          {l.rows.length ? (
            <ul className="grid gap-3">
              {l.rows.map((r) => (
                <li key={r.enrolment_id} className="rounded-lg border border-line p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium text-ink">{r.name}</span>
                    <div className="flex flex-wrap items-center gap-1.5">
                      {r.sent ? <Badge tone="neutral">Sent</Badge> : null}
                      <Badge tone={r.errors.length ? 'danger' : 'success'} dot>
                        {r.errors.length ? `${r.errors.length} missing` : 'Complete'}
                      </Badge>
                    </div>
                  </div>
                  <p className="text-small text-muted">
                    {r.columns.C || r.columns.D} · enrolled {shortDate(r.enrolled_on)}
                  </p>
                  {r.errors.length || r.warnings.length ? (
                    <ul className="mt-2 grid gap-1 text-small">
                      {r.errors.map((e) => (
                        <li key={`${e.code ?? ''}${e.column}`} className="flex items-start gap-1.5">
                          <XCircle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden />
                          <span>
                            <span className="sr-only">Missing: </span>
                            {e.problem}{' '}
                            <span className="text-muted">
                              ({e.column}, {e.field})
                            </span>
                            <FixLink p={e} cohortId={id} />
                          </span>
                        </li>
                      ))}
                      {r.warnings.map((e) => (
                        <li key={`${e.code ?? ''}${e.column}`} className="flex items-start gap-1.5 text-muted">
                          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
                          <span>
                            <span className="sr-only">Note: </span>
                            {e.problem}
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="mt-2 flex items-center gap-1.5 text-small text-success">
                      <CheckCircle2 className="size-4" aria-hidden /> Ready for the QCTO
                    </p>
                  )}
                  {can('people.manage') && r.errors.length ? (
                    <Button size="sm" variant="secondary" className="mt-2" onClick={() => setFixing(r)}>
                      Fill in details<span className="sr-only"> for {r.name}</span>
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-small text-muted">Learners appear here once they have signed their learner agreement.</p>
          )}
        </Card>
        <Card>
          <CardHeader title="Sent to the QCTO" />
          {l.sent.length ? (
            <ul className="grid gap-2 text-small">
              {l.sent.map((s) => (
                <li key={s.id} className="grid gap-1 border-b border-line pb-2">
                  <span>
                    <span className="font-medium text-ink">{shortDate(s.sent_on)}</span> · {s.rows}{' '}
                    {s.rows === 1 ? 'learner' : 'learners'}
                  </span>
                  {s.note ? <span className="text-muted">{s.note}</span> : null}
                  {s.response_loaded_at ? (
                    <span className="text-muted">
                      QCTO reply: {s.accepted ?? 0} accepted, {s.rejected ?? 0} rejected
                      {s.unmatched ? `, ${s.unmatched} not matched` : ''}
                    </span>
                  ) : null}
                  {(s.learners ?? [])
                    .filter((x) => x.status === 'rejected')
                    .map((x) => (
                      <span key={x.user_id} className="flex flex-wrap items-center gap-1.5">
                        <XCircle className="size-4 text-danger" aria-hidden />
                        {x.name}: {x.reason}
                        {x.resolved ? (
                          <Badge tone="success">Fixed, to send again</Badge>
                        ) : manage && x.outcome_id ? (
                          <ResolveButton outcomeId={x.outcome_id} cohortId={id} name={x.name} />
                        ) : null}
                      </span>
                    ))}
                  {manage ? (
                    <Button size="sm" variant="ghost" onClick={() => setReplying(s)}>
                      Load the QCTO's reply<span className="sr-only"> for {shortDate(s.sent_on)}</span>
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-small text-muted">Not sent yet.</p>
          )}
          <p className="mt-3 text-small text-muted">
            A second file (readiness for the external assessment) is due at least three months before the EISA. It is
            added with assessments.
          </p>
        </Card>
      </div>
      {fixing ? <FixDialog row={fixing} cohortId={id} onClose={() => setFixing(null)} /> : null}
      {sending ? <SentDialog cohortId={id} count={l.unsent} onClose={() => setSending(false)} /> : null}
      {replying ? <ReplyDialog cohortId={id} s={replying} onClose={() => setReplying(null)} /> : null}
      {confirming !== null ? (
        <Dialog
          open
          onOpenChange={(o) => !o && setConfirming(null)}
          title="Check the warnings first"
          description={`${l.warnings ?? 0} ${l.warnings === 1 ? 'warning' : 'warnings'}: the QCTO accepts these, but check each is right (for example optional equity details sent as Unknown).`}
          footer={
            <>
              <Button variant="secondary" onClick={() => setConfirming(null)}>
                Cancel
              </Button>
              <Button
                loading={downloading}
                onClick={() => {
                  const only = confirming;
                  setConfirming(null);
                  void download(only);
                }}
              >
                I have checked them: download
              </Button>
            </>
          }
        >
          <p className="text-small text-muted">This is recorded with the download.</p>
        </Dialog>
      ) : null}
    </>
  );
}

type FixField =
  | 'title'
  | 'address_line1'
  | 'address_line2'
  | 'city'
  | 'province'
  | 'postal_code'
  | 'statssa_area_code'
  | 'home_language'
  | 'socioeconomic_status'
  | 'nationality'
  | 'citizen_status';

/** Staff fill in a learner's missing details (the learner can also do it under My profile). */
function FixDialog({ row, cohortId, onClose }: { row: Row; cohortId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['people', row.user_id, 'profile'],
    queryFn: () =>
      call<{ data: Record<FixField, string | null> }>('GET', `/people/${row.user_id}/profile`).then((r) => r.data),
  });
  const [draft, setDraft] = useState<Partial<Record<FixField, string | null>>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () => call('PATCH', `/people/${row.user_id}/profile`, draft),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['qcto', cohortId] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { title: [errorMessage(e)] }),
  });
  const v = (k: FixField) => (k in draft ? (draft[k] ?? '') : (q.data?.[k] ?? ''));
  const set = (k: FixField) => (e: { target: { value: string } }) =>
    setDraft({ ...draft, [k]: e.target.value || null });
  const sel = (k: FixField, label: string, options: Record<string, string> | string[]) => (
    <Select label={label} value={v(k)} onChange={set(k)}>
      <option value="">Choose…</option>
      {(Array.isArray(options) ? options.map((o) => [o, o]) : Object.entries(options)).map(([value, text]) => (
        <option key={value} value={value}>
          {text}
        </option>
      ))}
    </Select>
  );
  const f = (k: FixField, label: string, hint?: string) => (
    <Field label={label} value={v(k)} onChange={set(k)} error={errors[k]?.[0]} hint={hint} />
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Details for ${row.name}`}
      description="As the QCTO file needs them."
    >
      {q.isPending ? (
        <Skeleton className="h-48" />
      ) : (
        <div className="grid gap-3">
          <div className="grid gap-3 sm:grid-cols-2">
            {sel('title', 'Title', TITLES)}
            {sel('home_language', 'Home language', LANGUAGES)}
          </div>
          {f('address_line1', 'Street address', 'House number and street name')}
          <div className="grid gap-3 sm:grid-cols-2">
            {f('address_line2', 'Suburb or village')}
            {f('city', 'City or town')}
            {sel('province', 'Province', [...provinces])}
            {f('postal_code', 'Postal code')}
          </div>
          {f('statssa_area_code', 'Stats SA area code')}
          <div className="grid gap-3 sm:grid-cols-2">
            {sel('socioeconomic_status', 'Work status', WORK_STATUS)}
            {sel('nationality', 'Nationality', NATIONALITIES)}
            {sel('citizen_status', 'Citizen or resident', CITIZEN)}
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button loading={save.isPending} disabled={!Object.keys(draft).length} onClick={() => save.mutate()}>
              Save
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}

function SentDialog({ cohortId, count, onClose }: { cohortId: string; count: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ sent_on: new Date().toISOString().slice(0, 10), note: '' });
  const save = useMutation({
    mutationFn: () => call('POST', `/cohorts/${cohortId}/qcto-enrolment/sent`, { ...form, note: form.note || null }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['qcto', cohortId] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Mark as sent"
      description={`Records that the file with ${count} ${count === 1 ? 'learner' : 'learners'} was emailed to the QCTO. Learners enrolled later appear as new.`}
    >
      <div className="grid gap-3">
        <Field
          label="Date sent"
          type="date"
          value={form.sent_on}
          onChange={(e) => setForm({ ...form, sent_on: e.target.value })}
        />
        <Field
          label="Note (optional)"
          hint="For example who sent it, or the QCTO's reply"
          value={form.note}
          onChange={(e) => setForm({ ...form, note: e.target.value })}
        />
        {save.isError ? <p className="text-small text-danger">{errorMessage(save.error)}</p> : null}
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

function ResolveButton({ outcomeId, cohortId, name }: { outcomeId: string; cohortId: string; name: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const m = useMutation({
    mutationFn: () => call('POST', `/qcto-outcomes/${outcomeId}/resolved`),
    onSuccess: () => {
      toast(`${name} will be in the next file`);
      void qc.invalidateQueries({ queryKey: ['qcto', cohortId] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  return (
    <Button size="sm" variant="secondary" loading={m.isPending} onClick={() => m.mutate()}>
      Fixed<span className="sr-only"> for {name}</span>
    </Button>
  );
}

/** Load the QCTO's reply: their spreadsheet, or mark learners by hand from their email. */
function ReplyDialog({ cohortId, s, onClose }: { cohortId: string; s: Submission; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [marks, setMarks] = useState<Record<string, { status: 'accepted' | 'rejected'; reason: string }>>({});
  const [error, setError] = useState<string | null>(null);
  const load = useMutation({
    mutationFn: () => {
      if (file) {
        const form = new FormData();
        form.append('file', file);
        return call<{ loaded: { accepted: number; rejected: number; unmatched: number } }>(
          'POST',
          `/qcto-submissions/${s.id}/response`,
          form,
        );
      }
      return call<{ loaded: { accepted: number; rejected: number; unmatched: number } }>(
        'POST',
        `/qcto-submissions/${s.id}/response`,
        {
          outcomes: Object.entries(marks).map(([user_id, m]) => ({
            user_id,
            status: m.status,
            reason: m.reason || null,
          })),
        },
      );
    },
    onSuccess: (r) => {
      toast(
        `Loaded: ${r.loaded.accepted} accepted, ${r.loaded.rejected} rejected${r.loaded.unmatched ? `, ${r.loaded.unmatched} not matched` : ''}`,
      );
      void qc.invalidateQueries({ queryKey: ['qcto', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`The QCTO's reply to the file sent ${shortDate(s.sent_on)}`}
      description="Load the spreadsheet the QCTO sent back, or mark learners from their email. The file is read and not kept."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            loading={load.isPending}
            disabled={!file && Object.keys(marks).length === 0}
            onClick={() => load.mutate()}
          >
            Load
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="grid gap-1.5">
          <label htmlFor="reply-file" className="text-small font-medium text-ink">
            The QCTO's spreadsheet (.xlsx or .csv)
          </label>
          <input
            id="reply-file"
            type="file"
            accept=".xlsx,.csv"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
        </div>
        {!file ? (
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-small font-medium text-ink">Or mark by hand</legend>
            {(s.learners ?? []).map((x) => (
              <div key={x.user_id} className="grid gap-1 rounded-lg border border-line p-2">
                <Select
                  label={x.name}
                  value={marks[x.user_id]?.status ?? ''}
                  onChange={(e) => {
                    const v = e.target.value as 'accepted' | 'rejected' | '';
                    const next = { ...marks };
                    if (v === '') delete next[x.user_id];
                    else next[x.user_id] = { status: v, reason: marks[x.user_id]?.reason ?? '' };
                    setMarks(next);
                  }}
                >
                  <option value="">No change</option>
                  <option value="accepted">Accepted</option>
                  <option value="rejected">Rejected</option>
                </Select>
                {marks[x.user_id]?.status === 'rejected' ? (
                  <Field
                    label={`QCTO's reason for ${x.name}`}
                    value={marks[x.user_id]?.reason ?? ''}
                    onChange={(e) =>
                      setMarks({ ...marks, [x.user_id]: { status: 'rejected', reason: e.target.value } })
                    }
                  />
                ) : null}
              </div>
            ))}
          </fieldset>
        ) : null}
      </div>
    </Dialog>
  );
}
