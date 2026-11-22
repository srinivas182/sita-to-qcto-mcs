import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, ClipboardPaste, XCircle } from 'lucide-react';
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
  Skeleton,
  StatusBadge,
  Table,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { EnrolmentBadge } from './EnrolmentBadge';
import type { Module, QualificationDetail } from './types';

interface ImportResult {
  rows: (Omit<Module, 'id' | 'kind_label'> & { line: number })[];
  errors: { line: number; text: string; message: string }[];
  applied: boolean;
  data: QualificationDetail;
}

const groupsFor = (legacy: boolean) =>
  legacy
    ? ([
        ['fundamental', 'Fundamental'],
        ['core', 'Core'],
        ['elective', 'Elective'],
      ] as const)
    : ([
        ['KM', 'Knowledge modules'],
        ['PM', 'Practical skill modules'],
        ['WM', 'Work experience modules'],
      ] as const);

/** Edit one catalogue entry: SAQA details, gazetted dates, modules, checks, publishing. */
export function QualificationEditorPage() {
  const { id } = useParams({ from: '/app/catalogue/$id' });
  const qc = useQueryClient();
  const toast = useToast();
  const key = ['admin', 'qualification', id];
  const q = useQuery({
    queryKey: key,
    queryFn: () => call<{ data: QualificationDetail }>('GET', `/admin/qualifications/${id}`).then((r) => r.data),
  });
  const [importing, setImporting] = useState(false);
  const [editing, setEditing] = useState(false);
  const done = (d: QualificationDetail) => {
    qc.setQueryData(key, d);
    void qc.invalidateQueries({ queryKey: ['admin', 'catalogue'] });
  };
  const publish = useMutation({
    mutationFn: () =>
      call<{ data: QualificationDetail }>('POST', `/admin/qualifications/${id}/publish`).then((r) => r.data),
    onSuccess: (d) => {
      done(d);
      toast('Published: providers can now add it');
    },
  });

  if (q.isPending) return <Skeleton className="h-96" />;
  if (q.error) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const d = q.data;
  const checks = d.checks ?? { errors: [], warnings: [], totals: {} };

  return (
    <>
      <Link to="/catalogue" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden /> Catalogue
      </Link>
      <PageHeader
        eyebrow={`${d.type_label}${d.saqa_id ? ` · SAQA ${d.saqa_id}` : ''}`}
        title={d.title}
        description={
          <span className="inline-flex flex-wrap items-center gap-2">
            <StatusBadge status={d.status} /> {d.status === 'published' ? <EnrolmentBadge q={d} /> : null} NQF{' '}
            {d.nqf_level} · {d.credits} credits · version {d.version}
          </span>
        }
        actions={
          <>
            <Button variant="secondary" onClick={() => setEditing(true)}>
              Edit details
            </Button>
            <Button variant="secondary" icon={<ClipboardPaste className="size-4" />} onClick={() => setImporting(true)}>
              {d.modules.length ? 'Replace modules' : 'Paste modules'}
            </Button>
            {d.status !== 'published' ? (
              <Button loading={publish.isPending} disabled={checks.errors.length > 0} onClick={() => publish.mutate()}>
                Publish
              </Button>
            ) : null}
          </>
        }
      />
      {publish.error ? <Alert tone="danger">{errorMessage(publish.error)}</Alert> : null}

      <div className="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="grid content-start gap-5">
          {groupsFor(d.legacy).map(([g, label]) => {
            const rows = d.modules.filter((m) => (d.legacy ? m.group === g : m.kind === g));
            const credits = rows.reduce((n, m) => n + m.credits, 0);
            const rule = d.legacy
              ? g === 'elective'
                ? d.credit_rules?.elective_min
                : d.credit_rules?.[g as 'core']
              : undefined;
            return (
              <Card key={g}>
                <CardHeader
                  title={label}
                  subtitle={`${rows.length} · ${credits} credits${rule !== undefined ? ` (rule: ${g === 'elective' ? 'at least ' : ''}${rule})` : ''}`}
                />
                <Table
                  rows={rows}
                  rowKey={(m) => m.id}
                  empty={<p className="py-4 text-small text-muted">None yet.</p>}
                  columns={[
                    {
                      key: 'code',
                      header: 'Code',
                      cell: (m) => <span className="whitespace-nowrap tabular-nums">{m.code}</span>,
                    },
                    { key: 'title', header: 'Title', cell: (m) => m.title },
                    {
                      key: 'group',
                      header: d.legacy ? 'NQF' : 'Type',
                      cell: (m) => (d.legacy ? (m.nqf_level ?? '—') : m.group),
                    },
                    {
                      key: 'credits',
                      header: 'Credits',
                      cell: (m) => <span className="tabular-nums">{m.credits}</span>,
                    },
                  ]}
                />
              </Card>
            );
          })}
        </div>

        <div className="grid content-start gap-5">
          <Card>
            <CardHeader
              title="Checks"
              subtitle="Errors stop publishing; warnings are for you to confirm against the curriculum."
            />
            <ul className="grid gap-2 text-small">
              {checks.errors.map((e) => (
                <li key={e} className="flex gap-2">
                  <XCircle className="mt-0.5 size-4 shrink-0 text-danger" aria-label="Error" /> {e}
                </li>
              ))}
              {checks.warnings.map((w) => (
                <li key={w} className="flex gap-2">
                  <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-label="Warning" /> {w}
                </li>
              ))}
              {!checks.errors.length && !checks.warnings.length ? (
                <li className="flex gap-2">
                  <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Passed" /> Everything adds
                  up.
                </li>
              ) : null}
            </ul>
          </Card>
          <Card>
            <CardHeader title="Dates and partners" />
            <dl className="grid gap-3 text-small">
              {(
                [
                  ['Registration ends', d.registration_end],
                  ['Last date for enrolment', d.last_enrolment_on],
                  ['Last date for achievement', d.last_achievement_on],
                  ['Quality partner', d.quality_partner],
                  ['Assessment partner', d.assessment_partner],
                  ['External assessment (EISA)', d.eisa ? 'Yes' : 'No'],
                  ['Curriculum code', d.curriculum_code],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="text-muted">{label}</dt>
                  <dd className="text-right text-ink">{value ?? '—'}</dd>
                </div>
              ))}
            </dl>
            {d.successors.length ? (
              <div className="mt-4">
                <p className="text-small font-medium">Replaced by</p>
                <ul className="mt-1 grid gap-1">
                  {d.successors.map((s) => (
                    <li key={s.id} className="text-small">
                      <Link to="/catalogue/$id" params={{ id: s.id }} className="text-primary-700 hover:underline">
                        {s.title}
                      </Link>{' '}
                      <Badge tone="neutral">{s.status}</Badge>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            {d.source_url ? (
              <a
                href={d.source_url}
                target="_blank"
                rel="noreferrer"
                className="mt-4 inline-block text-small text-primary-700 hover:underline"
              >
                SAQA record
              </a>
            ) : null}
            {d.notes ? <p className="mt-3 whitespace-pre-line text-small text-muted">{d.notes}</p> : null}
          </Card>
        </div>
      </div>

      {importing ? <ImportModules d={d} onClose={() => setImporting(false)} onDone={done} /> : null}
      {editing ? <EditDetails d={d} onClose={() => setEditing(false)} onDone={done} /> : null}
    </>
  );
}

function ImportModules({
  d,
  onClose,
  onDone,
}: {
  d: QualificationDetail;
  onClose: () => void;
  onDone: (d: QualificationDetail) => void;
}) {
  const [text, setText] = useState('');
  const [preview, setPreview] = useState<ImportResult | null>(null);
  const run = useMutation({
    mutationFn: (apply: boolean) =>
      call<ImportResult>('POST', `/admin/qualifications/${d.id}/modules`, { text, apply }),
    onSuccess: (r) => {
      if (r.applied) {
        onDone(r.data);
        onClose();
      } else setPreview(r);
    },
  });
  const example = d.legacy
    ? '119073 | Build masonry superstructures using solid units | 1 | 12 | elective'
    : '641201001-KM-01  Industry contexts  NQF 4  8';

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={d.legacy ? 'Paste unit standards' : 'Paste modules'}
      description="Copy the module table from the curriculum document or a spreadsheet. Nothing is saved until you check the preview."
    >
      <div className="grid gap-3">
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">One per line</span>
          <textarea
            className="min-h-40 rounded-lg border border-line bg-surface p-2 font-mono text-small"
            placeholder={example}
            value={text}
            onChange={(e) => {
              setText(e.target.value);
              setPreview(null);
            }}
          />
        </label>
        {preview ? (
          <div className="grid gap-2">
            <p className="text-small">
              <strong>{preview.rows.length}</strong> ready
              {preview.errors.length ? `, ${preview.errors.length} to fix` : ''}.
              {d.modules.length ? ' Saving replaces the current list.' : ''}
            </p>
            {preview.errors.length ? (
              <ul className="grid max-h-40 gap-1 overflow-auto rounded-lg bg-danger-bg p-2 text-small">
                {preview.errors.map((e) => (
                  <li key={`${e.line}-${e.message}`}>
                    <strong>Line {e.line}:</strong> {e.message} <span className="text-muted">“{e.text}”</span>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}
        {run.error ? <Alert tone="danger">{errorMessage(run.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          {preview && !preview.errors.length && preview.rows.length ? (
            <Button loading={run.isPending} onClick={() => run.mutate(true)}>
              Save {preview.rows.length}
            </Button>
          ) : (
            <Button loading={run.isPending} disabled={!text.trim()} onClick={() => run.mutate(false)}>
              Check
            </Button>
          )}
        </div>
      </div>
    </Dialog>
  );
}

function EditDetails({
  d,
  onClose,
  onDone,
}: {
  d: QualificationDetail;
  onClose: () => void;
  onDone: (d: QualificationDetail) => void;
}) {
  const [form, setForm] = useState({
    saqa_id: d.saqa_id ?? '',
    curriculum_code: d.curriculum_code ?? '',
    title: d.title,
    nqf_level: String(d.nqf_level),
    credits: String(d.credits),
    quality_partner: d.quality_partner ?? '',
    assessment_partner: d.assessment_partner ?? '',
    registration_end: d.registration_end ?? '',
    last_enrolment_on: d.last_enrolment_on ?? '',
    last_achievement_on: d.last_achievement_on ?? '',
    fundamental: String(d.credit_rules?.fundamental ?? ''),
    core: String(d.credit_rules?.core ?? ''),
    elective_min: String(d.credit_rules?.elective_min ?? ''),
    eisa: d.eisa,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const n = (v: string) => (v === '' ? null : Number(v));
  const save = useMutation({
    mutationFn: () => {
      const { fundamental, core, elective_min, ...rest } = form;
      const body: Record<string, unknown> = Object.fromEntries(
        Object.entries(rest).map(([k, v]) => [k, v === '' ? null : v]),
      );
      body.nqf_level = n(form.nqf_level);
      body.credits = n(form.credits);
      if (d.legacy) body.credit_rules = { fundamental: n(fundamental), core: n(core), elective_min: n(elective_min) };
      return call<{ data: QualificationDetail }>('PATCH', `/admin/qualifications/${d.id}`, body).then((r) => r.data);
    },
    onSuccess: (r) => {
      onDone(r);
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const f = (k: Exclude<keyof typeof form, 'eisa'>, label: string, extra: Record<string, unknown> = {}) => (
    <Field
      label={label}
      value={form[k]}
      onChange={(e) => setForm({ ...form, [k]: e.target.value })}
      error={errors[k]?.[0]}
      {...extra}
    />
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Edit details"
      description="Every date change is kept in the audit trail. Published entries get a new version."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {f('title', 'Title')}
        <div className="grid gap-3 sm:grid-cols-2">
          {f('saqa_id', 'SAQA ID', { inputMode: 'numeric' })}
          {f('curriculum_code', 'Curriculum code', { inputMode: 'numeric' })}
          {f('nqf_level', 'NQF level', { inputMode: 'numeric' })}
          {f('credits', 'Credits', { inputMode: 'numeric' })}
          {f('quality_partner', 'Quality partner')}
          {f('assessment_partner', 'Assessment partner')}
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          {f('registration_end', 'Registration ends', { type: 'date' })}
          {f('last_enrolment_on', 'Last enrolment', { type: 'date' })}
          {f('last_achievement_on', 'Last achievement', { type: 'date' })}
        </div>
        {d.legacy ? (
          <div className="grid gap-3 sm:grid-cols-3">
            {f('fundamental', 'Fundamental credits', { inputMode: 'numeric' })}
            {f('core', 'Core credits', { inputMode: 'numeric' })}
            {f('elective_min', 'Elective credits (at least)', { inputMode: 'numeric' })}
          </div>
        ) : (
          <label className="flex items-center gap-2 text-small">
            <input type="checkbox" checked={form.eisa} onChange={(e) => setForm({ ...form, eisa: e.target.checked })} />{' '}
            Has an external integrated summative assessment (EISA)
          </label>
        )}
        {save.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(save.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
