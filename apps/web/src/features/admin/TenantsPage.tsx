import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { Building2, Plus } from 'lucide-react';
import { useDeferredValue, useState, type FormEvent } from 'react';
import {
  Alert,
  Button,
  Card,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Skeleton,
  StatusBadge,
  Table,
  cx,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import type { Paginated, TenantRow } from './types';

const filters = ['all', 'pending', 'verified', 'active', 'suspended'] as const;

export function TenantsPage() {
  const can = useCan();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<(typeof filters)[number]>('all');
  const [creating, setCreating] = useState(false);
  const q = useDeferredValue(search);
  const list = useQuery({
    queryKey: ['admin', 'tenants', q, status],
    queryFn: () =>
      call<Paginated<TenantRow>>('GET', '/admin/tenants', undefined, {
        search: q || undefined,
        status: status === 'all' ? undefined : status,
        per_page: 50,
      }),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Training providers"
        description="Every provider gets its own space, subdomain and audit trail. Approve to go live; suspend to pause access instantly."
        actions={
          can('platform.tenants.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              New provider
            </Button>
          ) : null
        }
      />
      <Card>
        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="flex gap-1 overflow-x-auto rounded-lg bg-line-soft p-1">
            {filters.map((f) => (
              <button
                key={f}
                onClick={() => setStatus(f)}
                className={cx(
                  'rounded-md px-3 py-1.5 text-small font-medium capitalize',
                  status === f ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink',
                )}
              >
                {f}
              </button>
            ))}
          </div>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search name or code"
            className="h-9 flex-1 rounded-lg border border-line bg-surface px-3 text-body sm:max-w-xs sm:ml-auto"
            aria-label="Search providers"
          />
        </div>
        {list.isPending ? (
          <div className="grid gap-2">
            {Array.from({ length: 5 }, (_, i) => (
              <Skeleton key={i} className="h-11" />
            ))}
          </div>
        ) : (
          <Table
            rows={list.data?.data ?? []}
            rowKey={(r) => r.id}
            onRowClick={(r) => void navigate({ to: '/tenants/$id', params: { id: r.id } })}
            empty={
              <EmptyState icon={<Building2 className="size-5" />} title="No providers match">
                Try another filter.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Provider',
                cell: (r) => (
                  <div>
                    <Link to="/tenants/$id" params={{ id: r.id }} className="font-medium">
                      {r.trading_name}
                    </Link>
                    <div className="text-small text-muted">{r.legal_name}</div>
                  </div>
                ),
              },
              {
                key: 'domain',
                header: 'Primary domain',
                cell: (r) => (
                  <span className="text-muted">{r.domains?.find((d) => d.is_primary)?.hostname ?? '—'}</span>
                ),
              },
              { key: 'cell', header: 'Cell', cell: (r) => <code className="text-small">{r.cell_id}</code> },
              {
                key: 'centres',
                header: 'Centres',
                cell: (r) => <span className="tabular-nums">{r.centres_count ?? 0}</span>,
                className: 'text-right',
              },
              { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
        )}
      </Card>
      <CreateTenantDialog open={creating} onOpenChange={setCreating} />
    </>
  );
}

function CreateTenantDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [form, setForm] = useState({ legal_name: '', trading_name: '', code: '', registration_number: '' });
  const [error, setError] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () =>
      call<{ data: TenantRow }>('POST', '/admin/tenants', {
        ...form,
        registration_number: form.registration_number || null,
      }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      onOpenChange(false);
      void navigate({ to: '/tenants/$id', params: { id: r.data.id } });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const suggest = (name: string) =>
    name
      .toLowerCase()
      .replace(/\(pty\)\s*ltd|npc|cc/g, '')
      .trim()
      .split(/\s+/)
      .map((w) => w[0])
      .join('')
      .replace(/[^a-z0-9]/g, '');

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    create.mutate();
  };

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title="New training provider"
      description="Creates the provider as pending with a free subdomain. Approve it when documents check out."
    >
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label="Registered name"
          required
          value={form.legal_name}
          onChange={(e) => setForm({ ...form, legal_name: e.target.value, code: form.code || suggest(e.target.value) })}
          placeholder="Training Young Minds (Pty) Ltd"
        />
        <Field
          label="Trading name"
          value={form.trading_name}
          onChange={(e) => setForm({ ...form, trading_name: e.target.value })}
          placeholder="Training Young Minds"
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Code"
            required
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value.toLowerCase() })}
            hint={form.code ? `${form.code}.<platform domain>` : 'Used for the subdomain and the app'}
          />
          <Field
            label="CIPC registration"
            value={form.registration_number}
            onChange={(e) => setForm({ ...form, registration_number: e.target.value })}
            placeholder="2019/123456/07"
          />
        </div>
        <div className="mt-2 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" loading={create.isPending}>
            Create provider
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
