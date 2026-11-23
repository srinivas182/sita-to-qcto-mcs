import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { CalendarDays, CheckCircle2, PartyPopper } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, Field, Skeleton } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { PublicLayout } from '@/features/agreements/PublicLayout';
import { day, type OfferView } from './types';

/**
 * Public: the offer of a place, opened from the WhatsApp or SMS link. Accepting records
 * POPIA consent (with a parent or guardian for a learner under 18) and sends the learner
 * agreement to sign; signing it completes the enrolment.
 */
export function OfferPage() {
  const { token } = useParams({ strict: false }) as { token: string };
  const qc = useQueryClient();
  const key = ['offer', token];
  const q = useQuery({
    queryKey: key,
    queryFn: () => call<{ data: OfferView }>('GET', `/public/offers/${token}`).then((r) => r.data),
    retry: false,
  });
  const [agree, setAgree] = useState(false);
  const [guardian, setGuardian] = useState({ name: '', phone: '', relationship: '' });
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { offer: [errorMessage(e)] });
  const done = (r: { data: OfferView }) => {
    setErrors({});
    qc.setQueryData(key, r.data);
  };
  const accept = useMutation({
    mutationFn: (o: OfferView) =>
      call<{ data: OfferView }>('POST', `/public/offers/${token}/accept`, {
        consent: agree,
        consent_version: o.consent.version,
        guardian: o.needs_guardian ? guardian : null,
      }),
    onSuccess: done,
    onError: fail,
  });
  const decline = useMutation({
    mutationFn: () => call<{ data: OfferView }>('POST', `/public/offers/${token}/decline`, { reason: reason || null }),
    onSuccess: done,
    onError: fail,
  });

  if (q.isPending)
    return (
      <PublicLayout>
        <Skeleton className="h-64" />
      </PublicLayout>
    );
  if (q.isError)
    return (
      <PublicLayout>
        <Card>
          <h1 className="text-[20px] font-semibold">This link no longer works</h1>
          <p className="mt-2 text-muted">Check the latest message from the training provider, or contact them.</p>
        </Card>
      </PublicLayout>
    );

  const o = q.data;
  const err = (k: string) => errors[k]?.[0] ?? null;
  const g = (k: keyof typeof guardian) => (e: { target: { value: string } }) =>
    setGuardian({ ...guardian, [k]: e.target.value });

  return (
    <PublicLayout>
      <div>
        <p className="text-small text-muted">
          {o.provider} · {o.reference}
        </p>
        <h1 className="text-[22px] font-semibold tracking-tight text-ink">
          {o.status === 'open' ? `${o.learner}, you are offered a place` : o.course.title}
        </h1>
      </div>

      <Card>
        <h2 className="font-semibold text-ink">{o.course.title}</h2>
        <p className="mt-1 flex items-center gap-1.5 text-small text-muted">
          <CalendarDays className="size-4" aria-hidden /> {o.course.cohort}: {day(o.course.starts_on)} to{' '}
          {day(o.course.ends_on)}
        </p>
        {o.status === 'open' ? <p className="mt-2 text-small text-ink">Please reply by {day(o.expires_at)}.</p> : null}
      </Card>

      {o.status === 'accepted' ? (
        <Card>
          <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-success" aria-hidden />
            <div>
              <h2 className="font-semibold text-ink">You accepted. One step left.</h2>
              <p className="text-small text-muted">
                We sent you the learner agreement by WhatsApp or SMS
                {o.guardian
                  ? `, and to ${o.guardian.name} to sign as your ${o.guardian.relationship.toLowerCase()}`
                  : ''}
                . You are enrolled once everyone has signed.
              </p>
            </div>
          </div>
        </Card>
      ) : o.status !== 'open' ? (
        <Alert tone="info">
          {o.status === 'declined'
            ? 'You declined this offer. Thank you for letting us know.'
            : o.status === 'lapsed'
              ? 'This offer has lapsed because it was not accepted in time. Contact the training provider if you are still interested.'
              : 'This offer has been withdrawn. The training provider will be in touch.'}
        </Alert>
      ) : declining ? (
        <Card>
          <h2 className="mb-3 font-semibold text-ink">Decline the offer</h2>
          <Field label="Reason (optional)" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          <div className="mt-3 flex gap-2">
            <Button variant="danger" loading={decline.isPending} onClick={() => decline.mutate()}>
              Decline
            </Button>
            <Button variant="secondary" onClick={() => setDeclining(false)}>
              Back
            </Button>
          </div>
        </Card>
      ) : (
        <Card>
          <h2 className="mb-1 flex items-center gap-2 font-semibold text-ink">
            <PartyPopper className="size-5 text-primary-600" aria-hidden /> Accept your place
          </h2>
          {err('offer') ? <Alert tone="danger">{err('offer')}</Alert> : null}
          <form
            className="mt-3 grid gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              accept.mutate(o);
            }}
          >
            <section aria-labelledby="consent-title" className="rounded-lg border border-line p-3">
              <h3 id="consent-title" className="text-small font-semibold text-ink">
                How {o.provider} uses your information
              </h3>
              <div className="mt-2 grid gap-2 text-small text-muted" tabIndex={0} aria-label="Consent wording">
                {o.consent.text.split(/\n\s*\n/).map((p, i) => (
                  <p key={i}>{p}</p>
                ))}
              </div>
            </section>
            {o.needs_guardian ? (
              <fieldset className="grid gap-3 rounded-lg border border-line p-3">
                <legend className="px-1 text-small font-semibold text-ink">Parent or guardian</legend>
                <p className="text-small text-muted">
                  You are under 18, so a parent or guardian gives consent with you and signs the agreement too.
                </p>
                <Field
                  label="Their full name"
                  autoComplete="off"
                  value={guardian.name}
                  onChange={g('name')}
                  error={err('guardian.name') ?? err('guardian')}
                />
                <Field
                  label="Their phone number"
                  inputMode="tel"
                  value={guardian.phone}
                  onChange={g('phone')}
                  error={err('guardian.phone')}
                />
                <Field
                  label="Relationship to you"
                  hint="For example Mother, Father, Aunt, Legal guardian"
                  value={guardian.relationship}
                  onChange={g('relationship')}
                  error={err('guardian.relationship')}
                />
              </fieldset>
            ) : null}
            <label className="flex items-start gap-2 text-small">
              <input
                type="checkbox"
                className="mt-0.5 size-4"
                checked={agree}
                onChange={(e) => setAgree(e.target.checked)}
              />
              {o.needs_guardian
                ? 'We have read this and agree to how the information is used.'
                : 'I have read this and agree to how my information is used.'}
            </label>
            {(err('consent') ?? err('consent_version')) ? (
              <span className="text-small text-danger" role="alert">
                {err('consent') ?? err('consent_version')}
              </span>
            ) : null}
            <div className="flex flex-wrap gap-2">
              <Button type="submit" loading={accept.isPending}>
                Accept the place
              </Button>
              <Button type="button" variant="secondary" onClick={() => setDeclining(true)}>
                Decline
              </Button>
            </div>
          </form>
        </Card>
      )}
    </PublicLayout>
  );
}
