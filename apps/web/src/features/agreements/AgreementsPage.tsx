import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { FileSignature, Plus } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  Table,
  cx,
} from '@/components/ui';
import { ApiError, call } from '@/lib/api';
import { useCan, useMe } from '@/lib/session';
import type { EmployerDetail, EmployerRow } from '@/features/employers/types';
import {
  agreementState,
  KIND_LABEL,
  ROLE_LABEL,
  signerState,
  when,
  type AgreementDetail,
  type AgreementRow,
  type Role,
  type TemplateFamily,
} from './types';

const filters = [
  ['', 'All'],
  ['sent', 'Waiting'],
  ['completed', 'Signed'],
  ['declined', 'Declined'],
  ['expired', 'Expired'],
] as const;

/** Agreements sent for signing: who has signed, who is still to sign, and the signed copies. */
export function AgreementsPage() {
  const can = useCan();
  const [status, setStatus] = useState('');
  const [sending, setSending] = useState(false);
  const q = useQuery({
    queryKey: ['agreements', status],
    queryFn: () =>
      call<{ data: AgreementRow[]; counts: Record<string, number> }>('GET', '/agreements', undefined, {
        status: status || undefined,
      }),
  });
  const counts = q.data?.counts ?? {};

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Agreements"
        description="Workplace, employer and learner agreements, signed on the phone with a one-time PIN. Every signed copy carries a page that shows who signed, when, and how to check it."
        actions={
          <div className="flex gap-2">
            <Link
              to="/agreements/templates"
              className="inline-flex h-9 items-center rounded-lg border border-line px-3 text-small font-medium text-ink hover:bg-line-soft"
            >
              Wording
            </Link>
            {can('agreements.manage') ? (
              <Button icon={<Plus className="size-4" />} onClick={() => setSending(true)}>
                Send agreement
              </Button>
            ) : null}
          </div>
        }
      />
      <Card>
        <div className="mb-4 flex gap-1 rounded-lg bg-line-soft p-1 sm:w-fit">
          {filters.map(([key, label]) => (
            <button
              key={key}
              type="button"
              aria-pressed={status === key}
              onClick={() => setStatus(key)}
              className={cx(
                'rounded-md px-3 py-1 text-small',
                status === key ? 'bg-surface text-ink shadow-sm' : 'text-muted',
              )}
            >
              {label}
              {key && counts[key] ? <span className="ml-1.5 tabular-nums text-faint">{counts[key]}</span> : null}
            </button>
          ))}
        </div>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(a) => a.id}
            empty={
              <EmptyState icon={<FileSignature className="size-5" />} title="No agreements yet">
                Publish your wording under Wording, then send the first agreement.
              </EmptyState>
            }
            columns={[
              {
                key: 'title',
                header: 'Agreement',
                cell: (a) => (
                  <div className="min-w-0">
                    <Link to="/agreements/$id" params={{ id: a.id }} className="font-medium text-ink hover:underline">
                      {a.title}
                    </Link>
                    <div className="text-small text-muted">
                      {KIND_LABEL[a.kind] ?? a.kind}
                      {a.signature_level === 'advanced' ? ' · advanced e-signature' : ''} · sent {when(a.sent_at)}
                    </div>
                  </div>
                ),
              },
              {
                key: 'signers',
                header: 'Signatures',
                cell: (a) => (
                  <div className="flex flex-wrap gap-1">
                    {a.signers.map((s) => (
                      <Badge key={s.role} tone={signerState[s.status]?.tone ?? 'neutral'}>
                        {ROLE_LABEL[s.role]}: {signerState[s.status]?.label ?? s.status}
                      </Badge>
                    ))}
                  </div>
                ),
              },
              {
                key: 'status',
                header: 'Status',
                cell: (a) => (
                  <Badge tone={agreementState[a.status].tone} dot>
                    {agreementState[a.status].label}
                  </Badge>
                ),
              },
            ]}
          />
        )}
      </Card>
      {sending ? <SendDialog onClose={() => setSending(false)} /> : null}
    </>
  );
}

interface SignerForm {
  role: Role;
  name: string;
  phone: string;
  email: string;
}

function SendDialog({ onClose }: { onClose: () => void }) {
  const me = useMe();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    template_id: '',
    employer_id: '',
    site_id: '',
    mentor_id: '',
    qualification_id: '',
    start_date: '',
    end_date: '',
  });
  const [signers, setSigners] = useState<Record<Role, SignerForm>>({
    learner: { role: 'learner', name: '', phone: '', email: '' },
    employer: { role: 'employer', name: '', phone: '', email: '' },
    provider: {
      role: 'provider',
      name: `${me.user.first_name} ${me.user.last_name}`,
      phone: '',
      email: me.user.email ?? '',
    },
  });
  const templates = useQuery({
    queryKey: ['agreement-templates'],
    queryFn: () => call<{ data: TemplateFamily[] }>('GET', '/agreement-templates'),
  });
  const employers = useQuery({
    queryKey: ['employers', ''],
    queryFn: () => call<{ data: EmployerRow[] }>('GET', '/employers'),
  });
  const employer = useQuery({
    queryKey: ['employers', 'detail', form.employer_id],
    queryFn: () => call<{ data: EmployerDetail }>('GET', `/employers/${form.employer_id}`),
    enabled: form.employer_id !== '',
  });
  const published = (templates.data?.data ?? []).filter((t) => t.published_id);
  const template = published.find((t) => t.published_id === form.template_id);
  const site = employer.data?.data.sites.find((s) => s.id === form.site_id);
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const setSigner = (role: Role, k: keyof SignerForm) => (e: { target: { value: string } }) =>
    setSigners({ ...signers, [role]: { ...signers[role], [k]: e.target.value } });
  const err = (k: string) => errors[k]?.[0] ?? null;

  const pickEmployer = (id: string) => {
    setForm({ ...form, employer_id: id, site_id: '', mentor_id: '', qualification_id: '' });
    const row = employers.data?.data.find((e) => e.id === id);
    if (row) {
      setSigners((s) => ({
        ...s,
        employer: {
          role: 'employer',
          name: row.contact_name ?? '',
          phone: row.contact_phone ?? '',
          email: row.contact_email ?? '',
        },
      }));
    }
  };

  const send = useMutation({
    mutationFn: () =>
      call<{ data: AgreementDetail }>('POST', '/agreements', {
        template_id: form.template_id,
        employer_id: form.employer_id || null,
        site_id: form.site_id || null,
        mentor_id: form.mentor_id || null,
        qualification_id: form.qualification_id || null,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        signers: (template?.parties ?? []).map((r) => ({
          role: r,
          name: signers[r].name,
          phone: signers[r].phone || null,
          email: signers[r].email || null,
        })),
      }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['agreements'] });
      onClose();
      void navigate({ to: '/agreements/$id', params: { id: r.data.id } });
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const needsEmployer = template ? template.parties.includes('employer') : false;

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Send an agreement"
      description="We fill in the details, send each person a link, and confirm each signature with a one-time PIN."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          send.mutate();
        }}
      >
        <Select label="Agreement" value={form.template_id} onChange={set('template_id')}>
          <option value="">Choose…</option>
          {published.map((t) => (
            <option key={t.id} value={t.published_id ?? ''}>
              {t.name} (version {t.published_version})
            </option>
          ))}
        </Select>
        {templates.data && !published.length ? (
          <Alert tone="info">Publish your wording first, under Wording.</Alert>
        ) : null}
        {template?.advanced_signature ? (
          <Alert tone="info">
            This agreement uses an advanced e-signature through LAWtrust. Everyone needs an email address.
          </Alert>
        ) : null}
        {needsEmployer ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Host employer" value={form.employer_id} onChange={(e) => pickEmployer(e.target.value)}>
              <option value="">Choose…</option>
              {employers.data?.data.map((e) => (
                <option key={e.id} value={e.id}>
                  {e.legal_name}
                </option>
              ))}
            </Select>
            <Select label="Workplace" value={form.site_id} onChange={set('site_id')} disabled={!employer.data}>
              <option value="">Choose…</option>
              {employer.data?.data.sites.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
            <Select
              label="Qualification"
              value={form.qualification_id}
              onChange={set('qualification_id')}
              disabled={!site}
            >
              <option value="">Choose…</option>
              {site?.qualifications.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.title}
                </option>
              ))}
            </Select>
            <Select label="Workplace mentor" value={form.mentor_id} onChange={set('mentor_id')} disabled={!site}>
              <option value="">Choose…</option>
              {employer.data?.data.mentors
                .filter((m) => m.active && m.site_ids.includes(form.site_id))
                .map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
            </Select>
          </div>
        ) : null}
        {err('site_id') ? <Alert tone="danger">{err('site_id')}</Alert> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Starts"
            type="date"
            value={form.start_date}
            onChange={set('start_date')}
            error={err('start_date')}
          />
          <Field label="Ends" type="date" value={form.end_date} onChange={set('end_date')} error={err('end_date')} />
        </div>
        {(template?.parties ?? []).map((role, i) => (
          <fieldset key={role} className="grid gap-2 rounded-lg border border-line p-3">
            <legend className="px-1 text-small font-medium">{ROLE_LABEL[role]}</legend>
            <Field
              label="Full name"
              value={signers[role].name}
              onChange={setSigner(role, 'name')}
              error={err(`signers.${i}.name`)}
            />
            <div className="grid gap-2 sm:grid-cols-2">
              <Field
                label="Mobile"
                type="tel"
                value={signers[role].phone}
                onChange={setSigner(role, 'phone')}
                error={err(`signers.${i}.phone`)}
              />
              <Field
                label="Email"
                type="email"
                value={signers[role].email}
                onChange={setSigner(role, 'email')}
                error={err(`signers.${i}.email`)}
              />
            </div>
          </fieldset>
        ))}
        {err('template_id') || err('signers') ? (
          <Alert tone="danger">{err('template_id') ?? err('signers')}</Alert>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={send.isPending} disabled={!template}>
            Send for signing
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
