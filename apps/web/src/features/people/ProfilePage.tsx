import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Field,
  PageHeader,
  Select,
  Skeleton,
  Table,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { provinces } from '@/features/apply/types';
import { CITIZEN, DISABILITY, DISABILITY_RATING, LANGUAGES, NATIONALITIES, TITLES, WORK_STATUS } from './qcto';
import { CredentialDialog } from './CredentialDialog';
import { credentialState, type Credential } from './types';

interface Profile {
  id: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  id_type: 'sa_id' | 'passport' | null;
  id_number: string | null;
  id_country: string | null;
  dob: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  home_language: string | null;
  title: string | null;
  middle_name: string | null;
  nationality: string | null;
  citizen_status: string | null;
  socioeconomic_status: string | null;
  postal_same: boolean;
  postal_line1: string | null;
  postal_line2: string | null;
  postal_code_postal: string | null;
  statssa_area_code: string | null;
  emergency_name: string | null;
  emergency_phone: string | null;
  emergency_relationship: string | null;
  equity_consented: boolean;
  equity: {
    gender?: string;
    race?: string;
    disability?: boolean;
    disability_details?: string;
    disability_code?: string;
    disability_rating?: string;
  } | null;
}

type Errors = Record<string, string[]>;

interface QctoCheck {
  cohort: string;
  errors: { code: string; column: string; problem: string }[];
  warnings: { code: string; column: string; problem: string }[];
}

/** Your own details with this provider, and your registrations if you facilitate, assess, moderate or mentor. */
export function ProfilePage() {
  const qc = useQueryClient();
  const toast = useToast();
  const [qcto, setQcto] = useState<QctoCheck[] | null>(null);
  const q = useQuery({
    queryKey: ['me', 'profile'],
    queryFn: () =>
      call<{ data: Profile; qcto?: QctoCheck[] }>('GET', '/me/profile').then((r) => {
        setQcto(r.qcto ?? []);
        return r.data;
      }),
  });
  const creds = useQuery({
    queryKey: ['credentials', 'mine'],
    queryFn: () => call<{ data: Credential[] }>('GET', '/me/credentials').then((r) => r.data),
  });
  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Errors>({});
  const [warnings, setWarnings] = useState<string[]>([]);
  const [adding, setAdding] = useState<Credential | 'new' | null>(null);
  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      call<{ data: Profile; warnings: string[]; qcto?: QctoCheck[] }>('PATCH', '/me/profile', body),
    onSuccess: (r) => {
      qc.setQueryData(['me', 'profile'], r.data);
      setQcto(r.qcto ?? []);
      setDraft({});
      setErrors({});
      setWarnings(r.warnings);
      toast('Saved');
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });

  if (q.isPending) return <Skeleton className="h-96" />;
  if (q.error) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const p = q.data;
  const v = (k: keyof Profile) => (k in draft ? String(draft[k] ?? '') : String(p[k] ?? ''));
  const f = (k: keyof Profile, label: string, extra: Record<string, unknown> = {}) => (
    <Field
      label={label}
      value={v(k)}
      onChange={(e) => setDraft({ ...draft, [k]: e.target.value || null })}
      error={errors[k]?.[0]}
      {...extra}
    />
  );
  const sel = (k: keyof Profile, label: string, options: Record<string, string> | string[]) => (
    <Select label={label} value={v(k)} onChange={(e) => setDraft({ ...draft, [k]: e.target.value || null })}>
      <option value="">Choose…</option>
      {(Array.isArray(options) ? options.map((o) => [o, o]) : Object.entries(options)).map(([value, text]) => (
        <option key={value} value={value}>
          {text}
        </option>
      ))}
    </Select>
  );
  const postalSame = 'postal_same' in draft ? draft.postal_same === true : p.postal_same;
  const equity = { ...(p.equity ?? {}), ...((draft.equity as object | undefined) ?? {}) } as NonNullable<
    Profile['equity']
  >;
  const setEquity = (k: string, value: unknown) => setDraft({ ...draft, equity: { ...equity, [k]: value } });

  return (
    <>
      <PageHeader
        eyebrow="Account"
        title="My profile"
        description="Kept by your training provider for your records and reports. Only you and the people who run your records see it."
      />
      {qcto?.map((c) =>
        c.errors.length ? (
          <div key={c.cohort} className="mb-4">
            <Alert tone="warning">
              <p className="font-medium">For your {c.cohort} record with the QCTO, still to fix:</p>
              <ul className="mt-1 list-disc pl-5">
                {c.errors.map((e) => (
                  <li key={`${e.code}${e.column}`}>{e.problem}</li>
                ))}
              </ul>
            </Alert>
          </div>
        ) : null,
      )}
      {warnings.map((w) => (
        <div key={w} className="mb-4">
          <Alert tone="info">{w}</Alert>
        </div>
      ))}
      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <CardHeader title={`${p.first_name} ${p.last_name}`} subtitle={p.email ?? p.phone ?? ''} />
          <form
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              save.mutate(draft);
            }}
          >
            {p.id_number ? (
              <dl className="grid grid-cols-2 gap-3 text-small">
                <div>
                  <dt className="text-muted">
                    {p.id_type === 'passport' ? `Passport (${p.id_country})` : 'SA ID number'}
                  </dt>
                  <dd className="tabular-nums">{p.id_number}</dd>
                </div>
                <div>
                  <dt className="text-muted">Date of birth</dt>
                  <dd className="tabular-nums">{p.dob ?? '—'}</dd>
                </div>
              </dl>
            ) : (
              <div className="grid items-start gap-3 sm:grid-cols-[160px_1fr]">
                <Select
                  label="Identity document"
                  value={String(draft.id_type ?? 'sa_id')}
                  onChange={(e) => setDraft({ ...draft, id_type: e.target.value })}
                >
                  <option value="sa_id">SA ID</option>
                  <option value="passport">Passport</option>
                </Select>
                <Field
                  label="Number"
                  inputMode={draft.id_type === 'passport' ? 'text' : 'numeric'}
                  value={String(draft.id_number ?? '')}
                  onChange={(e) => setDraft({ ...draft, id_type: draft.id_type ?? 'sa_id', id_number: e.target.value })}
                  error={errors.id_number?.[0]}
                  hint="Checked before it is saved. Your date of birth comes from an SA ID."
                />
                {draft.id_type === 'passport' ? f('id_country', 'Issuing country (2 letters)', { maxLength: 2 }) : null}
              </div>
            )}
            <div className="grid gap-3 sm:grid-cols-[8rem_1fr]">
              {sel('title', 'Title', TITLES)}
              {f('middle_name', 'Middle names (if any)')}
            </div>
            {f('address_line1', 'Street address', {
              autoComplete: 'address-line1',
              hint: 'House number and street name, for example 12 Vilakazi Street',
            })}
            {f('address_line2', 'Suburb or village', { autoComplete: 'address-line2' })}
            <div className="grid gap-3 sm:grid-cols-3">
              {f('city', 'City or town', { autoComplete: 'address-level2' })}
              <Select
                label="Province"
                value={v('province')}
                onChange={(e) => setDraft({ ...draft, province: e.target.value || null })}
              >
                <option value="">Choose…</option>
                {provinces.map((x) => (
                  <option key={x}>{x}</option>
                ))}
              </Select>
              {f('postal_code', 'Postal code', { inputMode: 'numeric', autoComplete: 'postal-code' })}
            </div>
            <label className="flex items-center gap-2 text-small">
              <input
                type="checkbox"
                checked={postalSame}
                onChange={(e) => setDraft({ ...draft, postal_same: e.target.checked })}
              />
              My postal address is the same
            </label>
            {postalSame ? null : (
              <div className="grid gap-3 sm:grid-cols-3">
                {f('postal_line1', 'Postal address')}
                {f('postal_line2', 'Suburb or town')}
                {f('postal_code_postal', 'Postal code', { inputMode: 'numeric' })}
              </div>
            )}
            {f('statssa_area_code', 'Stats SA area code', {
              hint: 'Your training provider can fill this in from your address if you do not know it.',
            })}
            <div className="grid gap-3 sm:grid-cols-2">
              {sel('home_language', 'Home language', LANGUAGES)}
              {sel('socioeconomic_status', 'Work status', WORK_STATUS)}
              {sel('nationality', 'Nationality', NATIONALITIES)}
              {sel('citizen_status', 'Citizen or resident', CITIZEN)}
            </div>
            <fieldset className="grid gap-3">
              <legend className="text-small font-medium">In an emergency, contact</legend>
              <div className="grid gap-3 sm:grid-cols-3">
                {f('emergency_name', 'Name')}
                {f('emergency_phone', 'Mobile', { type: 'tel' })}
                {f('emergency_relationship', 'Relationship')}
              </div>
            </fieldset>
            <div className="flex justify-end">
              <Button type="submit" loading={save.isPending} disabled={!Object.keys(draft).length}>
                Save
              </Button>
            </div>
          </form>
        </Card>

        <Card>
          <CardHeader
            title="Equity information (optional)"
            subtitle="SETAs ask providers to report on gender, race and disability. Sharing is your choice."
          />
          {p.equity_consented ? (
            <div className="grid gap-3">
              <div className="grid gap-3 sm:grid-cols-2">
                <Select
                  label="Gender"
                  value={equity.gender ?? ''}
                  onChange={(e) => setEquity('gender', e.target.value || null)}
                >
                  <option value="">Not given</option>
                  <option value="female">Female</option>
                  <option value="male">Male</option>
                  <option value="non_binary">Non-binary</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </Select>
                <Select
                  label="Population group"
                  value={equity.race ?? ''}
                  onChange={(e) => setEquity('race', e.target.value || null)}
                >
                  <option value="">Not given</option>
                  <option value="african">African</option>
                  <option value="coloured">Coloured</option>
                  <option value="indian">Indian</option>
                  <option value="white">White</option>
                  <option value="other">Other</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </Select>
              </div>
              <label className="flex items-center gap-2 text-small">
                <input
                  type="checkbox"
                  checked={equity.disability === true}
                  onChange={(e) => setEquity('disability', e.target.checked)}
                />{' '}
                I live with a disability
              </label>
              {equity.disability ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Select
                    label="Kind of disability"
                    value={equity.disability_code ?? ''}
                    onChange={(e) => setEquity('disability_code', e.target.value || null)}
                  >
                    <option value="">Choose…</option>
                    {Object.entries(DISABILITY).map(([k, t]) => (
                      <option key={k} value={k}>
                        {t}
                      </option>
                    ))}
                  </Select>
                  <Select
                    label="Disability rating (QCTO code)"
                    value={equity.disability_rating ?? ''}
                    onChange={(e) => setEquity('disability_rating', e.target.value || null)}
                  >
                    <option value="">Choose…</option>
                    {DISABILITY_RATING.map((k) => (
                      <option key={k}>{k}</option>
                    ))}
                  </Select>
                </div>
              ) : null}
              <div className="flex flex-wrap justify-between gap-2">
                <Button variant="ghost" loading={save.isPending} onClick={() => save.mutate({ equity_consent: false })}>
                  Withdraw consent and delete
                </Button>
                <Button
                  loading={save.isPending}
                  disabled={!draft.equity}
                  onClick={() => save.mutate({ equity: draft.equity })}
                >
                  Save
                </Button>
              </div>
            </div>
          ) : (
            <div className="grid gap-3 text-small">
              <p>
                If you agree, this is stored encrypted and used only for SETA and QCTO equity reports. You can withdraw
                at any time, and it is then deleted.
              </p>
              <div>
                <Button onClick={() => save.mutate({ equity_consent: true })} loading={save.isPending}>
                  I agree to share equity information
                </Button>
              </div>
            </div>
          )}
        </Card>
      </div>

      <Card className="mt-5">
        <CardHeader
          title="My registrations"
          subtitle="If you facilitate, assess, moderate or mentor: your registration letters, checked by your provider."
          action={
            <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} onClick={() => setAdding('new')}>
              Add
            </Button>
          }
        />
        <Table
          rows={creds.data ?? []}
          rowKey={(c) => c.id}
          onRowClick={(c) => setAdding(c)}
          empty={<p className="py-4 text-small text-muted">None recorded.</p>}
          columns={[
            { key: 'role', header: 'Role', cell: (c) => c.role_label },
            { key: 'reg', header: 'Registration', cell: (c) => `${c.body} · ${c.registration_number}` },
            { key: 'until', header: 'Until', cell: (c) => c.expires_on ?? '—' },
            {
              key: 'state',
              header: 'Status',
              cell: (c) => (
                <Badge tone={credentialState[c.state].tone} dot>
                  {credentialState[c.state].label}
                </Badge>
              ),
            },
          ]}
        />
      </Card>
      {adding ? (
        <CredentialDialog
          personId={null}
          credential={adding === 'new' ? null : adding}
          onClose={() => setAdding(null)}
        />
      ) : null}
    </>
  );
}
