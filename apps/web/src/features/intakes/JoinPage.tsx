import { useMutation, useQuery } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { CheckCircle2, MapPin } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, Field, Select, Skeleton } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { PublicLayout } from '@/features/agreements/PublicLayout';
import { day, type Question } from './types';

interface PublicIntake {
  slug: string;
  title: string;
  description: string | null;
  places: number;
  min_age: number | null;
  max_age: number | null;
  questions: Question[] | null;
  accepting: boolean;
  opens_on: string;
  closes_on: string;
  provider: string;
  qualification: { title: string; saqa_id: string | null; nqf_level: number; credits: number } | null;
  centre: { name: string; city: string | null; province: string | null } | null;
  mode: string;
  starts_on: string;
  ends_on: string;
}

/** SA ID: 13 digits, a real date of birth and the Luhn check, as the server checks it. */
function saIdValid(value: string): boolean {
  const d = value.replace(/\D/g, '');
  if (d.length !== 13 || Number(d[10]) > 2) return false;
  let sum = 0;
  for (let i = 0; i < 13; i++) {
    let n = Number(d[i]);
    if (i % 2 === 1) {
      n *= 2;
      if (n > 9) n -= 9;
    }
    sum += n;
  }
  const m = Number(d.slice(2, 4));
  const day = Number(d.slice(4, 6));
  return sum % 10 === 0 && m >= 1 && m <= 12 && day >= 1 && day <= 31;
}

/** Public: an intake and its application form. The phone is proven with a PIN; no account needed. */
export function JoinPage() {
  const { slug } = useParams({ strict: false }) as { slug: string };
  const q = useQuery({
    queryKey: ['join', slug],
    queryFn: () => call<{ data: PublicIntake }>('GET', `/public/intakes/${slug}`).then((r) => r.data),
    retry: false,
  });
  const [phone, setPhone] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [form, setForm] = useState({
    code: '',
    first_name: '',
    last_name: '',
    id_type: 'sa_id',
    id_number: '',
    id_country: '',
    dob: '',
    email: '',
    website: '',
  });
  const [answers, setAnswers] = useState<Record<string, string | boolean>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [done, setDone] = useState<string | null>(null);
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { form: [errorMessage(e)] });
  const code = useMutation({
    mutationFn: () => call<{ data: { challenge_id: string } }>('POST', `/public/intakes/${slug}/code`, { phone }),
    onSuccess: (r) => {
      setErrors({});
      setChallenge(r.data.challenge_id);
    },
    onError: fail,
  });
  const apply = useMutation({
    mutationFn: () =>
      call<{ data: { reference: string } }>('POST', `/public/intakes/${slug}/apply`, {
        ...form,
        challenge_id: challenge,
        id_country: form.id_type === 'passport' ? form.id_country.toUpperCase() : null,
        dob: form.id_type === 'passport' ? form.dob : null,
        email: form.email || null,
        answers,
      }),
    onSuccess: (r) => setDone(r.data.reference),
    onError: fail,
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;

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
          <h1 className="text-[20px] font-semibold">We can't find this intake</h1>
          <p className="mt-2 text-muted">Check the link, or ask the training provider for a new one.</p>
        </Card>
      </PublicLayout>
    );
  const i = q.data;
  const idTyped = form.id_type === 'sa_id' && form.id_number.replace(/\D/g, '').length === 13;

  return (
    <PublicLayout>
      <div>
        <p className="text-small text-muted">{i.provider}</p>
        <h1 className="text-[24px] font-semibold tracking-tight text-ink">{i.title}</h1>
        {i.qualification ? (
          <p className="mt-1 text-muted">
            {i.qualification.title}
            {i.qualification.saqa_id ? ` · SAQA ${i.qualification.saqa_id}` : ''} · NQF {i.qualification.nqf_level}
          </p>
        ) : null}
      </div>
      <Card>
        <dl className="grid gap-3 text-small sm:grid-cols-2">
          <div>
            <dt className="text-muted">Where</dt>
            <dd className="font-medium text-ink">
              <MapPin className="mr-1 inline size-4 align-text-bottom" />
              {[i.centre?.name, i.centre?.city].filter(Boolean).join(', ')}
            </dd>
          </div>
          <div>
            <dt className="text-muted">When</dt>
            <dd className="font-medium text-ink">
              {day(i.starts_on)} to {day(i.ends_on)}
            </dd>
          </div>
          <div>
            <dt className="text-muted">Places</dt>
            <dd className="font-medium text-ink">{i.places}</dd>
          </div>
          <div>
            <dt className="text-muted">Apply by</dt>
            <dd className="font-medium text-ink">{day(i.closes_on)}</dd>
          </div>
          {i.min_age ? (
            <div>
              <dt className="text-muted">Age</dt>
              <dd className="font-medium text-ink">
                {i.min_age}
                {i.max_age ? ` to ${i.max_age}` : ' or older'}
              </dd>
            </div>
          ) : null}
        </dl>
        {i.description ? <p className="mt-4 whitespace-pre-line text-ink">{i.description}</p> : null}
      </Card>

      {done ? (
        <Card>
          <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-success" />
            <div>
              <h2 className="font-semibold text-ink">Application received</h2>
              <p className="text-muted">
                Your reference is <strong className="text-ink">{done}</strong>. {i.provider} will tell you about the
                next step by WhatsApp or SMS.
              </p>
            </div>
          </div>
        </Card>
      ) : !i.accepting ? (
        <Alert tone="info">This intake is not taking applications now.</Alert>
      ) : (
        <Card>
          <h2 className="mb-3 font-semibold text-ink">Apply</h2>
          {err('form') || err('intake') || err('application') ? (
            <Alert tone="danger">{err('form') ?? err('intake') ?? err('application')}</Alert>
          ) : null}
          {!challenge ? (
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                code.mutate();
              }}
            >
              <Field
                label="Your mobile number"
                type="tel"
                autoComplete="tel"
                required
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                error={err('phone')}
                hint="We send a PIN by WhatsApp or SMS to confirm it is yours."
              />
              <Button type="submit" loading={code.isPending}>
                Send me a PIN
              </Button>
            </form>
          ) : (
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                apply.mutate();
              }}
            >
              <Field
                label="PIN"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                required
                value={form.code}
                onChange={set('code')}
                error={err('code')}
              />
              <div className="grid gap-3 sm:grid-cols-2">
                <Field
                  label="First names"
                  autoComplete="given-name"
                  required
                  value={form.first_name}
                  onChange={set('first_name')}
                  error={err('first_name')}
                />
                <Field
                  label="Surname"
                  autoComplete="family-name"
                  required
                  value={form.last_name}
                  onChange={set('last_name')}
                  error={err('last_name')}
                />
              </div>
              <Select label="Identity document" value={form.id_type} onChange={set('id_type')}>
                <option value="sa_id">South African ID</option>
                <option value="passport">Passport</option>
              </Select>
              <Field
                label={form.id_type === 'sa_id' ? 'ID number' : 'Passport number'}
                inputMode={form.id_type === 'sa_id' ? 'numeric' : 'text'}
                required
                value={form.id_number}
                onChange={set('id_number')}
                error={
                  err('id_number') ??
                  (idTyped && !saIdValid(form.id_number)
                    ? 'That ID number does not look right. Check the 13 digits.'
                    : null)
                }
              />
              {form.id_type === 'passport' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field
                    label="Country (2 letters, e.g. ZW)"
                    maxLength={2}
                    required
                    value={form.id_country}
                    onChange={set('id_country')}
                    error={err('id_country')}
                  />
                  <Field
                    label="Date of birth"
                    type="date"
                    required
                    value={form.dob}
                    onChange={set('dob')}
                    error={err('dob')}
                  />
                </div>
              ) : null}
              <Field
                label="Email (optional)"
                type="email"
                autoComplete="email"
                value={form.email}
                onChange={set('email')}
                error={err('email')}
              />
              {(i.questions ?? []).map((qq) =>
                qq.type === 'yesno' ? (
                  <fieldset key={qq.key} className="grid gap-1.5 text-small">
                    <legend className="mb-1 font-medium">
                      {qq.label}
                      {qq.required ? ' *' : ''}
                    </legend>
                    <div className="flex gap-4">
                      {[true, false].map((v) => (
                        <label key={String(v)} className="flex items-center gap-2">
                          <input
                            type="radio"
                            name={qq.key}
                            checked={answers[qq.key] === v}
                            onChange={() => setAnswers({ ...answers, [qq.key]: v })}
                          />
                          {v ? 'Yes' : 'No'}
                        </label>
                      ))}
                    </div>
                    {err(`answers.${qq.key}`) ? <span className="text-danger">{err(`answers.${qq.key}`)}</span> : null}
                  </fieldset>
                ) : qq.type === 'choice' ? (
                  <div key={qq.key}>
                    <Select
                      label={`${qq.label}${qq.required ? ' *' : ''}`}
                      value={String(answers[qq.key] ?? '')}
                      onChange={(e) => setAnswers({ ...answers, [qq.key]: e.target.value })}
                    >
                      <option value="">Choose…</option>
                      {(qq.options ?? []).map((o) => (
                        <option key={o}>{o}</option>
                      ))}
                    </Select>
                    {err(`answers.${qq.key}`) ? (
                      <span className="text-small text-danger">{err(`answers.${qq.key}`)}</span>
                    ) : null}
                  </div>
                ) : (
                  <Field
                    key={qq.key}
                    label={`${qq.label}${qq.required ? ' *' : ''}`}
                    value={String(answers[qq.key] ?? '')}
                    onChange={(e) => setAnswers({ ...answers, [qq.key]: e.target.value })}
                    error={err(`answers.${qq.key}`)}
                  />
                ),
              )}
              <div aria-hidden className="absolute -left-[9999px]">
                <label>
                  Website
                  <input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} />
                </label>
              </div>
              <p className="text-small text-muted">
                {i.provider} uses your details to consider your application and, if you are accepted, to register you
                with the QCTO and the SETA.
              </p>
              <Button type="submit" loading={apply.isPending}>
                Send my application
              </Button>
            </form>
          )}
        </Card>
      )}
    </PublicLayout>
  );
}
