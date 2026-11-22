import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { Factory, Plus } from 'lucide-react';
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
} from '@/components/ui';
import { ApiError, call } from '@/lib/api';
import { useCan } from '@/lib/session';
import { approvalState, clean, type EmployerDetail, type EmployerRow } from './types';

const BBBEE_LABEL: Record<string, string> = {
  non_compliant: 'Non-compliant',
  eme: 'EME (exempt micro enterprise)',
  qse: 'QSE (qualifying small enterprise)',
};

interface ListResponse {
  data: EmployerRow[];
  setas: string[];
  bbbee: string[];
}

/** Host employers: the companies whose workplaces take learners for practical and work experience. */
export function EmployersPage() {
  const can = useCan();
  const [q, setQ] = useState('');
  const [adding, setAdding] = useState(false);
  const list = useQuery({
    queryKey: ['employers', q],
    queryFn: () => call<ListResponse>('GET', '/employers', undefined, { q: q || undefined }),
  });
  const rows = list.data?.data ?? [];
  const attention = rows.filter((r) => r.approval === 'expired' || r.approval === 'expiring').length;

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Employers"
        description="Host employers, their SETA-approved workplaces and workplace mentors. A workplace takes learners only while its approval is in date, up to its mentors' capacity."
        actions={
          can('employers.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
              Add employer
            </Button>
          ) : null
        }
      />
      {attention ? (
        <div className="mb-4">
          <Alert tone="warning">
            {attention} {attention === 1 ? 'employer has' : 'employers have'} a workplace approval that has ended or
            ends within 90 days. Owners are emailed at 90, 60 and 30 days.
          </Alert>
        </div>
      ) : null}
      <Card>
        <div className="mb-4 max-w-sm">
          <Field
            label="Search"
            type="search"
            placeholder="Name or CIPC number"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>
        {list.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={rows}
            rowKey={(r) => r.id}
            empty={
              <EmptyState icon={<Factory className="size-5" />} title="No employers yet">
                Add the companies that host your learners in the workplace.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Employer',
                cell: (r) => (
                  <div className="min-w-0">
                    <Link to="/employers/$id" params={{ id: r.id }} className="font-medium text-ink hover:underline">
                      {r.legal_name}
                    </Link>
                    <div className="text-small text-muted">
                      {[r.trading_name, r.registration_number].filter(Boolean).join(' · ') || '—'}
                    </div>
                  </div>
                ),
              },
              { key: 'seta', header: 'SETA', cell: (r) => <span className="text-small">{r.seta ?? '—'}</span> },
              {
                key: 'sites',
                header: 'Workplaces',
                cell: (r) => <span className="tabular-nums">{r.sites}</span>,
              },
              { key: 'mentors', header: 'Mentors', cell: (r) => <span className="tabular-nums">{r.mentors}</span> },
              {
                key: 'approval',
                header: 'Approval',
                cell: (r) => (
                  <Badge tone={approvalState[r.approval].tone} dot>
                    {approvalState[r.approval].label}
                  </Badge>
                ),
              },
            ]}
          />
        )}
      </Card>
      {adding && list.data ? (
        <EmployerDialog
          setas={list.data.setas}
          bbbee={list.data.bbbee}
          employer={null}
          onClose={() => setAdding(false)}
        />
      ) : null}
    </>
  );
}

export function EmployerDialog({
  setas,
  bbbee,
  employer,
  onClose,
}: {
  setas: string[];
  bbbee: string[];
  employer: EmployerDetail | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    legal_name: employer?.legal_name ?? '',
    trading_name: employer?.trading_name ?? '',
    registration_number: employer?.registration_number ?? '',
    sdl_number: employer?.sdl_number ?? '',
    seta: employer?.seta ?? '',
    bbbee_level: employer?.bbbee_level ?? '',
    sector: employer?.sector ?? '',
    contact_name: employer?.contact_name ?? '',
    contact_email: employer?.contact_email ?? '',
    contact_phone: employer?.contact_phone ?? '',
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const save = useMutation({
    mutationFn: () =>
      employer
        ? call<{ data: EmployerDetail }>('PATCH', `/employers/${employer.id}`, clean(form))
        : call<{ data: EmployerDetail }>('POST', '/employers', clean(form)),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['employers'] });
      onClose();
      if (!employer) void navigate({ to: '/employers/$id', params: { id: r.data.id } });
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={employer ? 'Change employer' : 'Add an employer'}
      description="As registered with CIPC and SARS. The levy number lets you claim grants for their learners."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field
          label="Registered name"
          required
          value={form.legal_name}
          onChange={set('legal_name')}
          error={err('legal_name')}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Trading name"
            value={form.trading_name}
            onChange={set('trading_name')}
            error={err('trading_name')}
          />
          <Field
            label="CIPC registration number"
            placeholder="2015/123456/07"
            value={form.registration_number}
            onChange={set('registration_number')}
            error={err('registration_number')}
          />
          <Field
            label="Skills levy (SDL) number"
            placeholder="L123456789"
            value={form.sdl_number}
            onChange={set('sdl_number')}
            error={err('sdl_number')}
          />
          <Select label="SETA" value={form.seta} onChange={set('seta')}>
            <option value="">Not known</option>
            {setas.map((s) => (
              <option key={s}>{s}</option>
            ))}
          </Select>
          <Select label="B-BBEE level" value={form.bbbee_level} onChange={set('bbbee_level')}>
            <option value="">Not known</option>
            {bbbee.map((b) => (
              <option key={b} value={b}>
                {BBBEE_LABEL[b] ?? `Level ${b}`}
              </option>
            ))}
          </Select>
          <Field label="Sector" value={form.sector} onChange={set('sector')} error={err('sector')} />
          <Field
            label="Contact person"
            value={form.contact_name}
            onChange={set('contact_name')}
            error={err('contact_name')}
          />
          <Field
            label="Contact phone"
            type="tel"
            value={form.contact_phone}
            onChange={set('contact_phone')}
            error={err('contact_phone')}
          />
        </div>
        <Field
          label="Contact email"
          type="email"
          value={form.contact_email}
          onChange={set('contact_email')}
          error={err('contact_email')}
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            {employer ? 'Save' : 'Add employer'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
