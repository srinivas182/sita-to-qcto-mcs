import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { CheckCircle2, Circle, FileText, Trash2, Upload } from 'lucide-react';
import { useDeferredValue, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Alert, Badge, Button, Card, Field, Select, Spinner, StatusBadge, cx, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money } from '@/lib/money';
import type { Package } from '@/features/billing/types';
import { ApplyLayout } from './ApplyLayout';
import { applicant, loadSaved, save, type Saved } from './session';
import { provinces, type ApplicationData } from './types';

type Errors = Record<string, string[]>;
const steps = ['Organisation', 'Accreditation', 'Centre & plan', 'Review'] as const;

/** Apply to join: four short steps, saved as you go, resumable from any device by email code. */
export function ApplyPage() {
  const [saved, setSaved] = useState<Saved | null>(() => loadSaved());
  const remember = (s: Saved | null) => {
    save(s);
    setSaved(s);
  };

  return (
    <ApplyLayout>
      {saved ? <Application saved={saved} onLost={() => remember(null)} /> : <Start onStarted={remember} />}
    </ApplyLayout>
  );
}

function Start({ onStarted }: { onStarted: (s: Saved) => void }) {
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', legal_name: '', company_fax: '' });
  const [errors, setErrors] = useState<Errors>({});
  const start = useMutation({
    mutationFn: () => call<{ id: string; token: string }>('POST', '/applications', form),
    onSuccess: (r) => onStarted({ id: r.id, token: r.token }),
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });

  return (
    <form
      className="grid gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        start.mutate();
      }}
    >
      <div>
        <h1 className="text-page-title font-semibold tracking-tight">Apply to join</h1>
        <p className="mt-1 text-body text-muted">
          For QCTO-accredited skills development providers. Takes about 10 minutes; you can stop and come back.
        </p>
      </div>
      {start.error && !(start.error instanceof ApiError && Object.keys(start.error.fields).length) ? (
        <Alert tone="danger">{errorMessage(start.error)}</Alert>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="First name"
          autoComplete="given-name"
          required
          value={form.first_name}
          onChange={set('first_name')}
          error={errors.first_name?.[0]}
        />
        <Field
          label="Last name"
          autoComplete="family-name"
          required
          value={form.last_name}
          onChange={set('last_name')}
          error={errors.last_name?.[0]}
        />
      </div>
      <Field
        label="Work email"
        type="email"
        autoComplete="email"
        required
        value={form.email}
        onChange={set('email')}
        error={errors.email?.[0]}
        hint="We send a code to confirm it."
      />
      <Field
        label="Registered company name"
        autoComplete="organization"
        required
        value={form.legal_name}
        onChange={set('legal_name')}
        error={errors.legal_name?.[0]}
        hint="As on the CIPC certificate."
      />
      {/* Hidden from people; bots fill it. */}
      <div aria-hidden="true" className="absolute -left-[9999px]">
        <label>
          Fax <input tabIndex={-1} autoComplete="off" value={form.company_fax} onChange={set('company_fax')} />
        </label>
      </div>
      <Button type="submit" loading={start.isPending} size="lg" block>
        Start application
      </Button>
      <p className="text-center text-small text-muted">
        Started already?{' '}
        <Link to="/apply/resume" className="font-medium text-primary-700 hover:underline">
          Continue your application
        </Link>
      </p>
    </form>
  );
}

function Application({ saved, onLost }: { saved: Saved; onLost: () => void }) {
  const client = applicant(saved);
  const qc = useQueryClient();
  const key = ['application', saved.id];
  const query = useQuery({ queryKey: key, queryFn: client.get, retry: false });

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 404) onLost();
  }, [query.error, onLost]);

  if (query.isPending) return <Spinner />;
  if (query.error) return <Alert tone="danger">{errorMessage(query.error)}</Alert>;
  const app = query.data;
  const setApp = (a: ApplicationData) => qc.setQueryData(key, a);

  if (!app.email_verified) return <VerifyEmail app={app} client={client} onVerified={setApp} />;
  if (app.status !== 'draft' && app.status !== 'info_requested') return <Status app={app} onDone={onLost} />;
  return <Wizard app={app} client={client} onChange={setApp} />;
}

function VerifyEmail({
  app,
  client,
  onVerified,
}: {
  app: ApplicationData;
  client: ReturnType<typeof applicant>;
  onVerified: (a: ApplicationData) => void;
}) {
  const [code, setCode] = useState('');
  const toast = useToast();
  const verify = useMutation({ mutationFn: () => client.verify(code), onSuccess: onVerified });
  const resend = useMutation({ mutationFn: client.resend, onSuccess: () => toast('A new code is on its way') });

  return (
    <form
      className="grid gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        verify.mutate();
      }}
    >
      <div>
        <h1 className="text-page-title font-semibold tracking-tight">Check your email</h1>
        <p className="mt-1 text-body text-muted">
          We sent a 6-digit code to <strong className="text-ink">{app.email}</strong>. Your reference is{' '}
          <strong className="text-ink">{app.reference}</strong> — keep it to continue later.
        </p>
      </div>
      <Field
        label="Code"
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        required
        value={code}
        onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        error={verify.error ? errorMessage(verify.error) : null}
      />
      <Button type="submit" size="lg" block loading={verify.isPending} disabled={code.length !== 6}>
        Confirm email
      </Button>
      <Button type="button" variant="ghost" loading={resend.isPending} onClick={() => resend.mutate()}>
        Send a new code
      </Button>
    </form>
  );
}

function Wizard({
  app,
  client,
  onChange,
}: {
  app: ApplicationData;
  client: ReturnType<typeof applicant>;
  onChange: (a: ApplicationData) => void;
}) {
  const [step, setStep] = useState(0);
  const [draft, setDraft] = useState<Partial<ApplicationData>>({});
  const [errors, setErrors] = useState<Errors>({});
  const toast = useToast();
  const top = useRef<HTMLDivElement>(null);
  const value = <K extends keyof ApplicationData>(k: K): ApplicationData[K] =>
    k in draft ? (draft[k] as ApplicationData[K]) : app[k];
  const field = (k: keyof ApplicationData, label: string, extra: Record<string, unknown> = {}) => (
    <Field
      label={label}
      value={String(value(k) ?? '')}
      onChange={(e) => setDraft({ ...draft, [k]: e.target.value === '' ? null : e.target.value })}
      error={errors[k]?.[0]}
      {...extra}
    />
  );

  const persist = useMutation({
    mutationFn: () => client.update(draft),
    onSuccess: (a) => {
      onChange(a);
      setDraft({});
      setErrors({});
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const submit = useMutation({ mutationFn: client.submit, onSuccess: onChange });

  const next = async (e: FormEvent) => {
    e.preventDefault();
    if (Object.keys(draft).length) {
      try {
        await persist.mutateAsync();
      } catch {
        return; // errors shown on the fields
      }
    }
    setStep((s) => Math.min(s + 1, steps.length - 1));
    top.current?.focus();
  };

  return (
    <div className="grid gap-6">
      <div ref={top} tabIndex={-1} className="outline-none">
        <p className="text-small text-muted">
          Reference <strong className="text-ink">{app.reference}</strong> · saved as you go
        </p>
        <ol className="mt-3 grid grid-cols-4 gap-2" aria-label="Steps">
          {steps.map((s, i) => (
            <li key={s}>
              <button
                type="button"
                onClick={() => setStep(i)}
                aria-current={i === step ? 'step' : undefined}
                className={cx(
                  'w-full border-t-4 pt-2 text-left text-small',
                  i <= step ? 'border-primary-600 text-ink' : 'border-line text-muted',
                )}
              >
                <span className="sr-only">Step {i + 1}: </span>
                {s}
              </button>
            </li>
          ))}
        </ol>
      </div>

      {app.status === 'info_requested' && app.message ? (
        <Alert tone="warning">
          <strong>The reviewer asked:</strong> {app.message}
        </Alert>
      ) : null}

      <form onSubmit={next} className="grid gap-4">
        {step === 0 ? (
          <>
            <div className="grid gap-4 sm:grid-cols-2">
              {field('first_name', 'First name')}
              {field('last_name', 'Last name')}
            </div>
            {field('phone', 'Mobile number', { type: 'tel', autoComplete: 'tel', placeholder: '082 123 4567' })}
            {field('legal_name', 'Registered company name')}
            {field('trading_name', 'Trading name (if different)')}
            {field('registration_number', 'CIPC registration number', {
              placeholder: '2019/123456/07',
              hint: <Hint check={app.checks.cipc_format} />,
            })}
            {field('vat_number', 'VAT number (if registered)', {
              inputMode: 'numeric',
              hint: app.checks.vat_format ? <Hint check={app.checks.vat_format} /> : null,
            })}
            {field('website', 'Website', { type: 'url', placeholder: 'https://' })}
          </>
        ) : null}

        {step === 1 ? (
          <>
            {field('sdp_number', 'QCTO accreditation number', {
              placeholder: '07-QCTO/SDP180825105627',
              hint: <Hint check={app.checks.sdp_format} />,
            })}
            {field('accreditation_expires_on', 'Accreditation valid until', {
              type: 'date',
              hint: <Hint check={app.checks.accreditation_current} />,
            })}
            <Field
              label="Qualifications in scope (SAQA IDs)"
              placeholder="101869, 91785"
              value={(value('scope') ?? []).join(', ')}
              onChange={(e) => setDraft({ ...draft, scope: e.target.value.split(/[\s,]+/).filter(Boolean) })}
              error={Object.entries(errors).find(([k]) => k.startsWith('scope'))?.[1][0]}
              hint="Separate with commas. Listed on your accreditation letter."
            />
            <Documents app={app} client={client} onChange={onChange} />
          </>
        ) : null}

        {step === 2 ? (
          <CentreAndPlan app={app} value={value} field={field} draft={draft} setDraft={setDraft} errors={errors} />
        ) : null}

        {step === 3 ? <Review app={app} client={client} onChange={onChange} /> : null}

        {persist.error && !Object.keys(errors).length ? (
          <Alert tone="danger">{errorMessage(persist.error)}</Alert>
        ) : null}
        {submit.error ? <Alert tone="danger">{errorMessage(submit.error)}</Alert> : null}

        <div className="flex justify-between gap-3">
          <Button type="button" variant="secondary" disabled={step === 0} onClick={() => setStep(step - 1)}>
            Back
          </Button>
          {step < steps.length - 1 ? (
            <Button type="submit" loading={persist.isPending}>
              Save and continue
            </Button>
          ) : (
            <Button
              type="button"
              loading={submit.isPending}
              disabled={!ready(app)}
              onClick={() => submit.mutate(undefined, { onSuccess: () => toast('Application sent') })}
            >
              {app.status === 'info_requested' ? 'Send again' : 'Submit application'}
            </Button>
          )}
        </div>
      </form>
    </div>
  );
}

const ready = (app: ApplicationData) => Object.values(app.checks).every((c) => c.ok || !c.required);

function Hint({ check }: { check?: { ok: boolean; message: string } }) {
  if (!check) return null;
  return (
    <span className={cx('inline-flex items-center gap-1', check.ok ? 'text-success' : 'text-muted')}>
      {check.ok ? <CheckCircle2 className="size-3.5" aria-hidden /> : <Circle className="size-3.5" aria-hidden />}
      {check.message}
    </span>
  );
}

function Documents({
  app,
  client,
  onChange,
}: {
  app: ApplicationData;
  client: ReturnType<typeof applicant>;
  onChange: (a: ApplicationData) => void;
}) {
  return (
    <div className="grid gap-3">
      <DocumentSlot
        kind="accreditation_letter"
        label="QCTO accreditation letter (required)"
        app={app}
        client={client}
        onChange={onChange}
      />
      <DocumentSlot
        kind="cipc_certificate"
        label="CIPC registration certificate (recommended)"
        app={app}
        client={client}
        onChange={onChange}
      />
    </div>
  );
}

function DocumentSlot({
  kind,
  label,
  app,
  client,
  onChange,
}: {
  kind: string;
  label: string;
  app: ApplicationData;
  client: ReturnType<typeof applicant>;
  onChange: (a: ApplicationData) => void;
}) {
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const current = app.files.find((f) => f.kind === kind);
  const remove = useMutation({ mutationFn: (id: string) => client.detach(id), onSuccess: onChange });

  const pick = async (file: File | undefined) => {
    if (!file) return;
    setError(null);
    if (file.size > 10 * 1024 * 1024) return setError('Files must be 10 MB or smaller.');
    setProgress(0);
    try {
      if (current) await client.detach(current.id);
      onChange(await client.upload(kind, file, setProgress));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setProgress(null);
      if (input.current) input.current.value = '';
    }
  };

  return (
    <div className="rounded-lg border border-dashed border-line p-4">
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="text-small font-medium text-ink">{label}</p>
          {current ? (
            <p className="mt-0.5 flex items-center gap-1.5 truncate text-small text-muted">
              <FileText className="size-3.5 shrink-0" aria-hidden /> {current.name}{' '}
              <StatusBadge status={current.status} />
            </p>
          ) : (
            <p className="mt-0.5 text-small text-muted">PDF, JPG or PNG, up to 10 MB</p>
          )}
        </div>
        <div className="flex shrink-0 gap-2">
          {current ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-label={`Remove ${current.name}`}
              loading={remove.isPending}
              onClick={() => remove.mutate(current.id)}
              icon={<Trash2 className="size-4" />}
            />
          ) : null}
          <Button
            type="button"
            variant="secondary"
            size="sm"
            icon={<Upload className="size-4" />}
            disabled={progress !== null}
            onClick={() => input.current?.click()}
          >
            {current ? 'Replace' : 'Upload'}
          </Button>
          <input
            ref={input}
            type="file"
            accept="application/pdf,image/jpeg,image/png"
            className="sr-only"
            aria-label={label}
            onChange={(e) => void pick(e.target.files?.[0])}
          />
        </div>
      </div>
      {progress !== null ? (
        <div
          className="mt-3 h-1.5 overflow-hidden rounded-full bg-line-soft"
          role="progressbar"
          aria-label="Upload progress"
          aria-valuenow={Math.round(progress * 100)}
          aria-valuemin={0}
          aria-valuemax={100}
        >
          <div
            className="h-full bg-primary-600 transition-[width]"
            style={{ width: `${Math.max(progress * 100, 4)}%` }}
          />
        </div>
      ) : null}
      {error ? (
        <p className="mt-2 text-small text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function CentreAndPlan({
  app,
  value,
  field,
  draft,
  setDraft,
  errors,
}: {
  app: ApplicationData;
  value: <K extends keyof ApplicationData>(k: K) => ApplicationData[K];
  field: (k: keyof ApplicationData, label: string, extra?: Record<string, unknown>) => ReactNode;
  draft: Partial<ApplicationData>;
  setDraft: (d: Partial<ApplicationData>) => void;
  errors: Errors;
}) {
  const code = useDeferredValue((value('preferred_code') ?? '').toLowerCase().trim());
  const availability = useQuery({
    queryKey: ['code-available', code],
    queryFn: () =>
      call<{ available: boolean; message: string | null; host: string }>(
        'GET',
        '/applications/code-available',
        undefined,
        { code },
      ),
    enabled: code.length >= 3,
  });
  const packages = useQuery({
    queryKey: ['packages'],
    queryFn: () => call<{ data: Package[]; example_pricing: boolean }>('GET', '/packages'),
  });
  const interval = value('interval') ?? 'month';

  return (
    <>
      {field('centre_name', 'Main centre name', { placeholder: 'e.g. Soweto Campus' })}
      {field('address_line1', 'Street address', { autoComplete: 'address-line1' })}
      <div className="grid gap-4 sm:grid-cols-3">
        {field('city', 'City or town', { autoComplete: 'address-level2' })}
        <Select
          label="Province"
          value={value('province') ?? ''}
          onChange={(e) => setDraft({ ...draft, province: e.target.value || null })}
        >
          <option value="">Choose…</option>
          {provinces.map((p) => (
            <option key={p}>{p}</option>
          ))}
        </Select>
        {field('postal_code', 'Postal code', { inputMode: 'numeric', autoComplete: 'postal-code' })}
      </div>
      {field('preferred_code', 'Your web address', {
        placeholder: 'yourname',
        autoCapitalize: 'none',
        trailing: <span className="text-small text-muted">.{app.base_domain}</span>,
        hint:
          code.length < 3 ? (
            'Letters, numbers and dashes. Learners sign in here.'
          ) : availability.data ? (
            <span className={availability.data.available ? 'text-success' : 'text-danger'}>
              {availability.data.available ? `${availability.data.host} is available` : availability.data.message}
            </span>
          ) : (
            'Checking…'
          ),
      })}

      <fieldset className="grid gap-3">
        <legend className="mb-1 flex w-full items-center justify-between text-small font-medium text-ink">
          Plan (free trial first; change any time)
          <span className="flex gap-1 rounded-md bg-line-soft p-0.5">
            {(['month', 'year'] as const).map((i) => (
              <button
                key={i}
                type="button"
                aria-pressed={interval === i}
                onClick={() => setDraft({ ...draft, interval: i })}
                className={cx(
                  'rounded px-2 py-0.5 text-small',
                  interval === i ? 'bg-surface text-ink shadow-sm' : 'text-muted',
                )}
              >
                {i === 'month' ? 'Monthly' : 'Yearly'}
              </button>
            ))}
          </span>
        </legend>
        {packages.data?.example_pricing ? (
          <Alert tone="warning">Example pricing — final prices are confirmed before your trial ends.</Alert>
        ) : null}
        {packages.data?.data.map((p) => (
          <label
            key={p.id}
            className={cx(
              'flex cursor-pointer items-start gap-3 rounded-lg border p-3',
              value('package_id') === p.id ? 'border-primary-500 bg-primary-50/40' : 'border-line',
            )}
          >
            <input
              type="radio"
              name="package"
              className="mt-1"
              checked={value('package_id') === p.id}
              onChange={() => setDraft({ ...draft, package_id: p.id })}
            />
            <span className="min-w-0 flex-1">
              <span className="flex items-center justify-between gap-2">
                <strong className="text-body">{p.name}</strong>
                <span className="text-small tabular-nums">
                  {money(interval === 'year' ? p.annual_fee_cents : p.base_fee_cents)}/
                  {interval === 'year' ? 'yr' : 'mo'} <span className="text-muted">excl. VAT</span>
                </span>
              </span>
              <span className="text-small text-muted">
                {p.included_learners} learners included · {p.trial_days}-day trial
              </span>
            </span>
          </label>
        ))}
        {errors.package_id ? <p className="text-small text-danger">{errors.package_id[0]}</p> : null}
      </fieldset>
    </>
  );
}

function Review({
  app,
  client,
  onChange,
}: {
  app: ApplicationData;
  client: ReturnType<typeof applicant>;
  onChange: (a: ApplicationData) => void;
}) {
  const consent = useMutation({ mutationFn: client.consent, onSuccess: onChange });
  const required = Object.entries(app.checks).filter(([, c]) => c.required);

  return (
    <div className="grid gap-4">
      <Card className="p-4">
        <h2 className="text-card-title font-semibold">Before you submit</h2>
        <ul className="mt-3 grid gap-2">
          {required.map(([key, c]) => (
            <li key={key} className="flex items-start gap-2 text-small">
              {c.ok ? (
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Done" />
              ) : (
                <Circle className="mt-0.5 size-4 shrink-0 text-faint" aria-label="To do" />
              )}
              <span className={c.ok ? 'text-ink' : 'text-muted'}>{c.message}</span>
            </li>
          ))}
        </ul>
      </Card>
      <label className="flex items-start gap-3 rounded-lg border border-line p-4 text-small">
        <input
          type="checkbox"
          className="mt-0.5"
          checked={app.consented || consent.isPending}
          disabled={app.consented || consent.isPending}
          onChange={() => consent.mutate()}
        />
        <span>
          I am authorised to apply for this organisation. I accept the terms of service and consent to my details and
          documents being processed to review this application, as described in the privacy notice (POPIA).
        </span>
      </label>
      <p className="text-small text-muted">
        A person checks every application against the CIPC and QCTO records, usually within 2 working days.
      </p>
    </div>
  );
}

function Status({ app, onDone }: { app: ApplicationData; onDone: () => void }) {
  const title = {
    submitted: 'Application received',
    approved: 'You are approved',
    declined: 'Application not approved',
  }[app.status as 'submitted'];
  return (
    <div className="grid gap-4 text-center">
      <div className="mx-auto">
        <StatusBadge status={app.status} />
      </div>
      <h1 className="text-page-title font-semibold tracking-tight">{title}</h1>
      {app.status === 'submitted' ? (
        <p className="text-body text-muted">
          Reference <strong className="text-ink">{app.reference}</strong>. We review it within about 2 working days and
          email {app.email}.
        </p>
      ) : null}
      {app.status === 'approved' ? (
        <p className="text-body text-muted">
          Check {app.email} for your invitation to{' '}
          <strong className="text-ink">
            {app.preferred_code}.{app.base_domain}
          </strong>
          .
        </p>
      ) : null}
      {app.status === 'declined' && app.message ? <Alert tone="danger">{app.message}</Alert> : null}
      <Badge tone="neutral">Questions? Reply to any email from us.</Badge>
      {app.status !== 'submitted' ? (
        <Button variant="ghost" onClick={onDone}>
          Start a new application
        </Button>
      ) : null}
    </div>
  );
}
