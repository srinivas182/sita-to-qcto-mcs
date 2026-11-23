import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, ExternalLink, XCircle } from 'lucide-react';
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
import { reqState, type LearnerDocument, type Requirement } from './documents';
import { appState, day, FUNDING, offerState, type ApplicationRow, type OfferView, type Question } from './types';

interface Detail extends Omit<ApplicationRow, 'applicant' | 'documents'> {
  pdf_file_id: string | null;
  documents_requested_at: string | null;
  intake: { id: string; title: string; questions: Question[] | null };
  applicant: {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    id_number: string | null;
    id_type: string | null;
    dob: string | null;
    age: number | null;
  };
  requirements: { complete: boolean; items: Requirement[] };
  documents: LearnerDocument[];
  offer: OfferView | null;
  places_left: number;
  identity_check: {
    id: string;
    status: string;
    checked_at: string | null;
    requested_at: string | null;
    problems: string[];
  } | null;
  identity_connected: boolean;
}

/** One applicant: who they are, their answers, and each document with its checks, to accept or reject. */
export function ApplicantPage() {
  const { id } = useParams({ from: '/app/applicants/$id' });
  const manage = useCan()('applications.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [reviewing, setReviewing] = useState<LearnerDocument | null>(null);
  const q = useQuery({
    queryKey: ['applications', 'one', id],
    queryFn: () => call<{ data: Detail }>('GET', `/learner-applications/${id}`).then((r) => r.data),
  });
  const request = useMutation({
    mutationFn: () =>
      call<{ data: { requested: Requirement[] } }>('POST', `/learner-applications/${id}/request-documents`),
    onSuccess: (r) => {
      toast(`Asked for ${r.data.requested.length} document(s) by WhatsApp or SMS`);
      void qc.invalidateQueries({ queryKey: ['applications', 'one', id] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const a = q.data;
  const missing = a.requirements.items.some((i) => ['missing', 'rejected', 'expired'].includes(i.state));

  return (
    <>
      <Link
        to="/intakes/$id"
        params={{ id: a.intake.id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> {a.intake.title}
      </Link>
      <PageHeader
        eyebrow={`${a.reference} · applied ${day(a.submitted_at)}`}
        title={a.applicant.name}
        description={[
          a.applicant.id_number,
          a.applicant.age != null ? `${a.applicant.age} years` : null,
          a.applicant.phone,
          a.applicant.email,
        ]
          .filter(Boolean)
          .join(' · ')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={appState[a.status].tone} dot>
              {appState[a.status].label}
            </Badge>
            {a.pdf_file_id ? (
              <Button
                size="sm"
                variant="secondary"
                icon={<ExternalLink className="size-4" />}
                onClick={() =>
                  void call<{ url: string }>('GET', `/files/${a.pdf_file_id}/download`)
                    .then((r) => window.open(r.url, '_blank', 'noopener'))
                    .catch((e: unknown) => toast(errorMessage(e), 'error'))
                }
              >
                Application PDF
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <CardHeader
            title="Documents"
            subtitle="Checked automatically when added: certification date, picture quality, and the ID number where the app read the card's barcode."
          />
          {a.documents.length ? (
            <ul className="grid gap-3">
              {a.documents.map((d) => (
                <li key={d.id} className="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-[8rem_1fr]">
                  <Preview doc={d} />
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-medium text-ink">{d.label}</span>
                      <Badge
                        tone={d.status === 'accepted' ? 'success' : d.status === 'rejected' ? 'danger' : 'warning'}
                        dot
                      >
                        {d.status === 'accepted' ? 'Accepted' : d.status === 'rejected' ? 'Rejected' : 'To check'}
                      </Badge>
                    </div>
                    <p className="text-small text-muted">
                      {d.certified
                        ? `Certified ${d.certified_on ? day(d.certified_on) : ''}${d.certified_by ? ` by ${d.certified_by}` : ''}`
                        : 'Not a certified copy'}{' '}
                      · added {day(d.added_at)}
                    </p>
                    <Checks doc={d} />
                    {d.reason ? <p className="text-small text-danger">Rejected: {d.reason}</p> : null}
                    {manage && d.status !== 'accepted' ? (
                      <Button size="sm" variant="secondary" className="mt-2" onClick={() => setReviewing(d)}>
                        Check this document<span className="sr-only">: {d.label}</span>
                      </Button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-small text-muted">No documents yet.</p>
          )}
        </Card>
        <div className="grid content-start gap-4">
          <OfferCard detail={a} manage={manage} />
          {a.identity_connected || a.identity_check ? <IdentityCard detail={a} manage={manage} /> : null}
          <Card>
            <CardHeader title="Required" />
            <ul className="grid gap-2">
              {a.requirements.items.map((i) => (
                <li key={i.type} className="flex items-start justify-between gap-2 text-small">
                  <span className="text-ink">{i.label}</span>
                  <Badge tone={reqState[i.state].tone}>{reqState[i.state].label}</Badge>
                </li>
              ))}
            </ul>
            {a.requirements.complete ? (
              <p className="mt-3 text-small text-success">All required documents accepted.</p>
            ) : null}
            {manage && missing ? (
              <Button
                className="mt-3 w-full"
                variant="secondary"
                loading={request.isPending}
                onClick={() => request.mutate()}
              >
                Ask for what is missing
              </Button>
            ) : null}
            {a.documents_requested_at ? (
              <p className="mt-2 text-small text-muted">Last asked {day(a.documents_requested_at)}</p>
            ) : null}
          </Card>
          <Card>
            <CardHeader title="Answers" />
            <dl className="grid gap-2 text-small">
              {(a.intake.questions ?? []).map((qq) => (
                <div key={qq.key}>
                  <dt className="text-muted">{qq.label}</dt>
                  <dd className="text-ink">
                    {a.answers?.[qq.key] === undefined
                      ? '—'
                      : typeof a.answers[qq.key] === 'boolean'
                        ? a.answers[qq.key]
                          ? 'Yes'
                          : 'No'
                        : String(a.answers[qq.key])}
                  </dd>
                </div>
              ))}
            </dl>
          </Card>
        </div>
      </div>
      {reviewing ? (
        <ReviewDialog
          doc={reviewing}
          applicantId={a.applicant.id_number}
          onClose={() => setReviewing(null)}
          onDone={() => void qc.invalidateQueries({ queryKey: ['applications'] })}
        />
      ) : null}
    </>
  );
}

function Checks({ doc }: { doc: LearnerDocument }) {
  const c = doc.checks ?? {};
  const row = (ok: boolean | null, text: string) => (
    <li className="flex items-start gap-1.5">
      {ok === null ? (
        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
      ) : ok ? (
        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
      ) : (
        <XCircle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden />
      )}
      <span>
        <span className="sr-only">{ok === null ? 'Check: ' : ok ? 'Passed: ' : 'Failed: '}</span>
        {text}
      </span>
    </li>
  );
  return (
    <ul className="mt-2 grid gap-1 text-small">
      {c.certification
        ? row(
            c.certification.ok,
            c.certification.ok
              ? 'Certification date within your rule'
              : (c.certification.problem ?? 'Certification problem'),
          )
        : null}
      {c.quality
        ? row(
            c.quality.ok,
            c.quality.ok ? `Picture readable (${c.quality.width}×${c.quality.height})` : c.quality.problems.join(' '),
          )
        : null}
      {doc.type === 'id_document'
        ? c.id_match === undefined
          ? row(null, 'Compare the number on the document with the applicant’s ID number')
          : row(
              c.id_match,
              c.id_match ? 'ID number matches the card’s barcode' : 'ID number on the card’s barcode does not match',
            )
        : null}
    </ul>
  );
}

function Preview({ doc }: { doc: LearnerDocument }) {
  const link = useQuery({
    queryKey: ['files', doc.file_id, 'download'],
    queryFn: () => call<{ url: string }>('GET', `/files/${doc.file_id}/download`),
    staleTime: 5 * 60_000,
  });
  if (!link.data) return <div className="h-24 rounded-md bg-line-soft" />;
  return doc.mime?.startsWith('image/') ? (
    <a href={link.data.url} target="_blank" rel="noreferrer" className="block">
      <img
        src={link.data.url}
        alt={`${doc.label}, as uploaded`}
        className="h-24 w-32 rounded-md border border-line object-cover"
      />
    </a>
  ) : (
    <a
      href={link.data.url}
      target="_blank"
      rel="noreferrer"
      className="inline-flex h-24 w-32 items-center justify-center gap-1 rounded-md border border-line text-small text-primary-700"
    >
      Open PDF <ExternalLink className="size-3.5" />
    </a>
  );
}

function ReviewDialog({
  doc,
  applicantId,
  onClose,
  onDone,
}: {
  doc: LearnerDocument;
  applicantId: string | null;
  onClose: () => void;
  onDone: () => void;
}) {
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const m = useMutation({
    mutationFn: (decision: 'accept' | 'reject') =>
      call('POST', `/learner-documents/${doc.id}/review`, {
        decision,
        reason: reason || null,
        id_confirmed: confirmed,
      }),
    onSuccess: () => {
      onDone();
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { decision: [errorMessage(e)] }),
  });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const needsConfirm = doc.type === 'id_document' && doc.checks?.id_match !== true;

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Check: ${doc.label}`}
      description="Accept it, or reject it with a reason the applicant will see."
    >
      <div className="grid gap-3">
        <Preview doc={doc} />
        <Checks doc={doc} />
        {needsConfirm ? (
          <label className="flex items-start gap-2 text-small">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={confirmed}
              onChange={(e) => setConfirmed(e.target.checked)}
            />
            <span>
              The number on the document matches the applicant’s ID number ({applicantId ?? 'not recorded'}).
              {err('id_confirmed') ? <span className="block text-danger">{err('id_confirmed')}</span> : null}
            </span>
          </label>
        ) : null}
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Reason, if you reject it</span>
          <textarea
            className="min-h-16 rounded-lg border border-line bg-surface p-2"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. The stamp is not readable"
          />
          {err('reason') ? <span className="text-danger">{err('reason')}</span> : null}
        </label>
        {err('decision') ? <Alert tone="danger">{err('decision')}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button
            variant="secondary"
            loading={m.isPending && m.variables === 'reject'}
            onClick={() => m.mutate('reject')}
          >
            Reject
          </Button>
          <Button loading={m.isPending && m.variables === 'accept'} onClick={() => m.mutate('accept')}>
            Accept
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

/** The offer: make one (documents in, a place free), see its state, or take it back. */
function OfferCard({ detail: a, manage }: { detail: Detail; manage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [making, setMaking] = useState(false);
  const [form, setForm] = useState({ days: '7', funding: '' });
  const [withdrawing, setWithdrawing] = useState(false);
  const [reason, setReason] = useState('');
  const refresh = () => void qc.invalidateQueries({ queryKey: ['applications'] });
  const make = useMutation({
    mutationFn: () =>
      call<{ data: OfferView }>('POST', `/learner-applications/${a.id}/offer`, {
        days: Number(form.days),
        funding: form.funding || null,
      }),
    onSuccess: () => {
      toast('Offer sent by WhatsApp or SMS');
      setMaking(false);
      refresh();
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const withdraw = useMutation({
    mutationFn: (id: string) => call('POST', `/offers/${id}/withdraw`, { reason }),
    onSuccess: () => {
      toast('Offer withdrawn');
      setWithdrawing(false);
      refresh();
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const o = a.offer;
  const canOffer = ['submitted', 'shortlisted', 'waitlisted'].includes(a.status);

  return (
    <Card>
      <CardHeader title="Offer" />
      {o && !(canOffer && o.status !== 'open') ? (
        <div className="grid gap-1.5 text-small">
          <Badge tone={offerState[o.status].tone} dot>
            {offerState[o.status].label}
          </Badge>
          <p className="text-muted">
            {o.status === 'open' ? `Reply by ${day(o.expires_at)}` : null}
            {o.funding ? `${o.status === 'open' ? ' · ' : ''}Funding: ${FUNDING[o.funding] ?? o.funding}` : null}
          </p>
          {o.guardian ? (
            <p className="text-muted">
              Parent or guardian: {o.guardian.name} ({o.guardian.relationship})
            </p>
          ) : null}
          {o.status === 'accepted' && o.agreement_id ? (
            <Link to="/agreements/$id" params={{ id: o.agreement_id }} className="text-primary-700 underline">
              {a.status === 'enrolled' ? 'Signed learner agreement' : 'Learner agreement: waiting for signatures'}
            </Link>
          ) : null}
          {manage && o.status === 'open' ? (
            <Button size="sm" variant="secondary" className="mt-1" onClick={() => setWithdrawing(true)}>
              Withdraw the offer
            </Button>
          ) : null}
        </div>
      ) : canOffer ? (
        <div className="grid gap-2 text-small">
          <p className="text-muted">
            {a.places_left} {a.places_left === 1 ? 'place' : 'places'} left.{' '}
            {a.requirements.complete ? null : 'An offer can be made once every required document is accepted.'}
          </p>
          {manage ? (
            <Button
              disabled={!a.requirements.complete || a.places_left < 1}
              onClick={() => setMaking(true)}
              className="w-full"
            >
              Make an offer
            </Button>
          ) : null}
        </div>
      ) : (
        <p className="text-small text-muted">No offer.</p>
      )}
      {making ? (
        <Dialog
          open
          onOpenChange={(v) => !v && setMaking(false)}
          title={`Offer ${a.applicant.name} a place`}
          description="Sent by WhatsApp or SMS with a link to accept. Accepting records consent and sends the learner agreement; signing it enrols them."
        >
          <div className="grid gap-3">
            <Field
              label="Days to reply"
              type="number"
              min={1}
              max={30}
              value={form.days}
              onChange={(e) => setForm({ ...form, days: e.target.value })}
            />
            <Select
              label="Funding"
              value={form.funding}
              onChange={(e) => setForm({ ...form, funding: e.target.value })}
            >
              <option value="">Not known yet</option>
              {Object.entries(FUNDING).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setMaking(false)}>
                Cancel
              </Button>
              <Button loading={make.isPending} onClick={() => make.mutate()}>
                Send the offer
              </Button>
            </div>
          </div>
        </Dialog>
      ) : null}
      {withdrawing && o ? (
        <Dialog
          open
          onOpenChange={(v) => !v && setWithdrawing(false)}
          title="Withdraw the offer"
          description="The applicant is told and goes back to the shortlist."
        >
          <div className="grid gap-3">
            <Field label="Reason" required value={reason} onChange={(e) => setReason(e.target.value)} />
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setWithdrawing(false)}>
                Cancel
              </Button>
              <Button
                variant="danger"
                loading={withdraw.isPending}
                disabled={reason.trim().length < 3}
                onClick={() => withdraw.mutate(o.id)}
              >
                Withdraw
              </Button>
            </div>
          </div>
        </Dialog>
      ) : null}
    </Card>
  );
}

const ID_STATE: Record<string, { label: string; tone: 'success' | 'danger' | 'warning' | 'neutral' | 'info' }> = {
  queued: { label: "Waiting for tonight's check", tone: 'info' },
  verified: { label: 'Matches Home Affairs', tone: 'success' },
  mismatch: { label: 'Does not match', tone: 'danger' },
  not_found: { label: 'Not found at Home Affairs', tone: 'danger' },
  deceased: { label: 'Recorded as deceased', tone: 'danger' },
  failed: { label: 'Could not be checked', tone: 'warning' },
};

/** Home Affairs ID check: asked for here, run in the nightly batch. */
function IdentityCard({ detail: a, manage }: { detail: Detail; manage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const ask = useMutation({
    mutationFn: () => call('POST', `/learner-applications/${a.id}/identity-check`),
    onSuccess: () => {
      toast("Added to tonight's Home Affairs check");
      void qc.invalidateQueries({ queryKey: ['applications', 'one', a.id] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const c = a.identity_check;
  const state = c ? (ID_STATE[c.status] ?? { label: c.status, tone: 'neutral' as const }) : null;
  return (
    <Card>
      <CardHeader title="Home Affairs ID check" />
      {c && state ? (
        <div className="grid gap-1.5 text-small">
          <Badge tone={state.tone} dot>
            {state.label}
          </Badge>
          {c.checked_at ? <p className="text-muted">Checked {day(c.checked_at)}</p> : null}
          {c.problems.map((p) => (
            <p key={p} className="text-danger">
              {p}
            </p>
          ))}
        </div>
      ) : (
        <p className="text-small text-muted">Not checked.</p>
      )}
      {manage && a.identity_connected && c?.status !== 'queued' && a.applicant.id_type === 'sa_id' ? (
        <Button size="sm" variant="secondary" className="mt-3" loading={ask.isPending} onClick={() => ask.mutate()}>
          {c ? 'Check again' : 'Check with Home Affairs'}
        </Button>
      ) : null}
    </Card>
  );
}
