import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  useToast,
} from '@/components/ui';
import { downloadFrom } from '@/features/delivery/RegistersPage';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';

interface EisaDate {
  key: string;
  eisa_on: string;
  closes_on: string;
  quality_partner: string | null;
  source: string | null;
  own: boolean;
}

interface Registration {
  id: string;
  eisa_on: string;
  closes_on: string;
  assessment_centre: string | null;
  assessment_centre_name: string | null;
  flc_code: string | null;
  flc_number: string | null;
  flc_file_id: string | null;
  declared_at: string | null;
  status: 'draft' | 'registered' | 'withdrawn';
}

interface Learner {
  enrolment_id: string;
  name: string | null;
  sor: string | null;
  registration: Registration | null;
  missing: string[];
}

interface Data {
  dates: EisaDate[];
  next: EisaDate | null;
  days_to_close: number | null;
  learners: Learner[];
  flc: Record<string, string>;
  flc_evidence: string[];
  qualification_id: string;
}

/** EISA dates for the cohort's qualification, and each ready learner's registration. */
export function EisaPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/eisa' });
  const can = useCan();
  const manage = can('cohorts.manage');
  const toast = useToast();
  const [editing, setEditing] = useState<Learner | null>(null);
  const [adding, setAdding] = useState(false);
  const q = useQuery({
    queryKey: ['eisa', id],
    queryFn: () => call<{ data: Data }>('GET', `/cohorts/${id}/eisa`).then((r) => r.data),
  });
  const register = useMutation({
    mutationFn: (regId: string) => call('POST', `/eisa-registrations/${regId}/register`),
    onSuccess: () => {
      toast('Registered for the EISA');
      void q.refetch();
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const d = q.data;
  const registered = d.learners.filter((l) => l.registration?.status === 'registered').length;
  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Cohort
      </Link>
      <PageHeader
        title="EISA registration"
        description="Register learners with a Statement of Results for the external assessment before the closing date. The QCTO allows no late registrations."
        actions={
          manage && registered > 0 ? (
            <Button
              variant="secondary"
              icon={<Download className="size-4" />}
              onClick={() =>
                void downloadFrom(`/cohorts/${id}/eisa-packs`, 'eisa-packs.zip').catch((e: unknown) =>
                  toast(errorMessage(e), 'error'),
                )
              }
            >
              All registration packs
            </Button>
          ) : null
        }
      />
      <div className="mb-4">
        {d.next ? (
          <Alert tone={(d.days_to_close ?? 99) <= 14 ? 'warning' : 'info'}>
            Next EISA {shortDate(d.next.eisa_on)}: registration closes {shortDate(d.next.closes_on)} ({d.days_to_close}{' '}
            days).
          </Alert>
        ) : (
          <Alert tone="warning">No open EISA date is published for this qualification yet.</Alert>
        )}
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <CardHeader title="Learners" />
          {d.learners.length === 0 ? (
            <EmptyState title="No learners">Learners appear once enrolled.</EmptyState>
          ) : (
            <ul className="divide-y divide-line">
              {d.learners.map((l) => {
                const r = l.registration;
                return (
                  <li key={l.enrolment_id} className="grid gap-1.5 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-medium text-ink">{l.name}</p>
                      {r?.status === 'registered' ? (
                        <Badge tone="success">Registered for {shortDate(r.eisa_on)}</Badge>
                      ) : l.sor ? (
                        <Badge tone="warning">Not registered</Badge>
                      ) : (
                        <Badge>No Statement of Results</Badge>
                      )}
                    </div>
                    {r ? (
                      <p className="text-small text-muted">
                        {r.assessment_centre_name ?? r.assessment_centre ?? 'No centre'} · FLC{' '}
                        {r.flc_code ? (d.flc[r.flc_code] ?? r.flc_code) : '—'} ·{' '}
                        {r.declared_at ? `declared ${shortDate(r.declared_at)}` : 'not declared'}
                      </p>
                    ) : null}
                    {r?.status !== 'registered' && l.missing.length ? (
                      <ul className="list-disc pl-5 text-small text-muted">
                        {l.missing.map((m) => (
                          <li key={m}>{m}</li>
                        ))}
                      </ul>
                    ) : null}
                    {manage && l.sor ? (
                      <div className="flex flex-wrap gap-2">
                        {r?.status !== 'registered' ? (
                          <Button size="sm" variant="secondary" onClick={() => setEditing(l)}>
                            {r ? 'Edit' : 'Start registration'}
                            <span className="sr-only"> for {l.name}</span>
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="ghost"
                            icon={<Download className="size-4" />}
                            onClick={() =>
                              void downloadFrom(`/eisa-registrations/${r.id}/pack`, 'eisa-pack.zip').catch(
                                (e: unknown) => toast(errorMessage(e), 'error'),
                              )
                            }
                          >
                            Pack<span className="sr-only"> for {l.name}</span>
                          </Button>
                        )}
                        {r && r.status === 'draft' && l.missing.length === 0 ? (
                          <Button
                            size="sm"
                            loading={register.isPending && register.variables === r.id}
                            onClick={() => register.mutate(r.id)}
                          >
                            Register<span className="sr-only"> {l.name}</span>
                          </Button>
                        ) : null}
                      </div>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
        <Card>
          <CardHeader
            title="EISA dates"
            action={
              manage ? (
                <Button size="sm" variant="ghost" onClick={() => setAdding(true)}>
                  Add a date
                </Button>
              ) : null
            }
          />
          {d.dates.length === 0 ? (
            <p className="text-small text-muted">None published yet.</p>
          ) : (
            <ul className="grid gap-2 text-small">
              {d.dates.map((x) => (
                <li key={x.key}>
                  <span className="font-medium text-ink">{shortDate(x.eisa_on)}</span> · closes {shortDate(x.closes_on)}
                  <span className="block text-muted">
                    {x.own ? 'Your date' : (x.quality_partner ?? 'Published')}
                    {x.source ? ` · ${x.source}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      {editing ? (
        <RegistrationDialog cohortId={id} learner={editing} data={d} onClose={() => setEditing(null)} />
      ) : null}
      {adding ? (
        <DateDialog cohortId={id} qualificationId={d.qualification_id} onClose={() => setAdding(false)} />
      ) : null}
    </>
  );
}

function RegistrationDialog({
  cohortId,
  learner,
  data,
  onClose,
}: {
  cohortId: string;
  learner: Learner;
  data: Data;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const r = learner.registration;
  const open = data.dates.filter((x) => x.closes_on >= new Date().toISOString().slice(0, 10));
  const [form, setForm] = useState({
    date: open.find((x) => x.eisa_on === r?.eisa_on)?.key ?? open[0]?.key ?? '',
    assessment_centre: r?.assessment_centre ?? '',
    assessment_centre_name: r?.assessment_centre_name ?? '',
    flc_code: r?.flc_code ?? '',
    flc_number: r?.flc_number ?? '',
    flc_file_id: r?.flc_file_id ?? '',
  });
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () =>
      call('PUT', `/enrolments/${learner.enrolment_id}/eisa-registration`, {
        ...form,
        flc_code: form.flc_code || null,
        flc_number: form.flc_number || null,
        flc_file_id: form.flc_file_id || null,
        assessment_centre: form.assessment_centre || null,
        assessment_centre_name: form.assessment_centre_name || null,
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['eisa', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const upload = async (f: File | undefined) => {
    if (!f) return;
    setUploading(true);
    try {
      const up = await uploadFile('flc_evidence', f);
      setForm((x) => ({ ...x, flc_file_id: up.id }));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setUploading(false);
    }
  };
  const needsEvidence = data.flc_evidence.includes(form.flc_code);
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`EISA registration: ${learner.name ?? 'learner'}`}
      description={`Statement of Results ${learner.sor ?? ''}. The learner signs the declaration in the app or on My results.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={uploading} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Select label="EISA date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })}>
          {open.map((x) => (
            <option key={x.key} value={x.key}>
              {shortDate(x.eisa_on)} (closes {shortDate(x.closes_on)})
            </option>
          ))}
        </Select>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Assessment centre accreditation number"
            value={form.assessment_centre}
            onChange={(e) => setForm({ ...form, assessment_centre: e.target.value })}
          />
          <Field
            label="Assessment centre name"
            value={form.assessment_centre_name}
            onChange={(e) => setForm({ ...form, assessment_centre_name: e.target.value })}
          />
        </div>
        <Select
          label="Foundational learning (FLC)"
          value={form.flc_code}
          onChange={(e) => setForm({ ...form, flc_code: e.target.value })}
        >
          <option value="">Choose…</option>
          {Object.entries(data.flc).map(([k, v]) => (
            <option key={k} value={k}>
              {k} · {v}
            </option>
          ))}
        </Select>
        <Field
          label="FLC, school or N3 certificate number (RPL: RPL and the date, RPLYYYYMMDD)"
          value={form.flc_number}
          onChange={(e) => setForm({ ...form, flc_number: e.target.value })}
        />
        {needsEvidence ? (
          <div className="grid gap-1.5">
            <label htmlFor="flc-evidence" className="text-small font-medium text-ink">
              FLC evidence (certificate or results){form.flc_file_id ? ': uploaded' : ''}
            </label>
            <input
              id="flc-evidence"
              type="file"
              accept="application/pdf,image/jpeg,image/png"
              onChange={(e) => void upload(e.target.files?.[0])}
            />
          </div>
        ) : null}
      </div>
    </Dialog>
  );
}

function DateDialog({
  cohortId,
  qualificationId,
  onClose,
}: {
  cohortId: string;
  qualificationId: string;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ eisa_on: '', closes_on: '', source: '' });
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => call('POST', '/eisa-dates', { ...form, qualification_id: qualificationId }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['eisa', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Add an EISA date"
      description="For a date the quality partner gave you that isn't in the published plan yet."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Add
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="EISA date"
            type="date"
            value={form.eisa_on}
            onChange={(e) => setForm({ ...form, eisa_on: e.target.value })}
          />
          <Field
            label="Registration closes"
            type="date"
            value={form.closes_on}
            onChange={(e) => setForm({ ...form, closes_on: e.target.value })}
          />
        </div>
        <Field
          label="Where the date comes from"
          value={form.source}
          onChange={(e) => setForm({ ...form, source: e.target.value })}
        />
      </div>
    </Dialog>
  );
}
