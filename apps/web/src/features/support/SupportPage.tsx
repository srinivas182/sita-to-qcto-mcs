import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LifeBuoy, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Alert,
  Button,
  Card,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  StatusBadge,
  Table,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useMe } from '@/lib/session';
import type { Paginated, TenantRow } from '../admin/types';

interface Grant {
  id: string;
  tenant_id: string;
  granted_to: string;
  reason: string;
  minutes: number;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string | null;
}

/**
 * Consent-based support access. Providers approve, deny or end requests; platform
 * staff ask and see their windows. Nobody enters a provider's space without consent.
 */
export function SupportPage() {
  const me = useMe();
  return me.tenant ? <ProviderSupport /> : <PlatformSupport />;
}

function ProviderSupport() {
  const qc = useQueryClient();
  const toast = useToast();
  const list = useQuery({
    queryKey: ['support'],
    queryFn: () => call<{ data: Grant[] }>('GET', '/support-access').then((r) => r.data),
    refetchInterval: 20_000,
  });
  const act = useMutation({
    mutationFn: ({ id, verb }: { id: string; verb: 'approve' | 'deny' | 'revoke' }) =>
      call('POST', `/support-access/${id}/${verb}`),
    onSuccess: (_d, v) => {
      toast({ approve: 'Access approved', deny: 'Request denied', revoke: 'Access ended' }[v.verb]);
      void qc.invalidateQueries({ queryKey: ['support'] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  return (
    <>
      <PageHeader
        eyebrow="Your provider"
        title="Support access"
        description="When the platform support team needs to look inside your space, they ask here first. Access is read-only, time-boxed and every visit shows in your audit log."
      />
      <Card>
        {list.isPending ? (
          <Skeleton className="h-32" />
        ) : (
          <Table
            rows={list.data ?? []}
            rowKey={(g) => g.id}
            empty={
              <EmptyState icon={<LifeBuoy className="size-5" />} title="No requests">
                You will see support requests here.
              </EmptyState>
            }
            columns={[
              { key: 'reason', header: 'Reason', cell: (g) => <span className="font-medium">{g.reason}</span> },
              { key: 'length', header: 'Window', cell: (g) => `${g.minutes} min` },
              {
                key: 'status',
                header: 'Status',
                cell: (g) => (
                  <div>
                    <StatusBadge status={g.status} />
                    {g.ends_at && g.status === 'active' ? (
                      <div className="text-caption text-muted">until {new Date(g.ends_at).toLocaleTimeString()}</div>
                    ) : null}
                  </div>
                ),
              },
              {
                key: 'actions',
                header: '',
                cell: (g) => (
                  <div className="flex justify-end gap-2">
                    {g.status === 'pending' ? (
                      <>
                        <Button size="sm" onClick={() => act.mutate({ id: g.id, verb: 'approve' })}>
                          Approve
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => act.mutate({ id: g.id, verb: 'deny' })}>
                          Deny
                        </Button>
                      </>
                    ) : null}
                    {g.status === 'active' ? (
                      <Button size="sm" variant="secondary" onClick={() => act.mutate({ id: g.id, verb: 'revoke' })}>
                        End now
                      </Button>
                    ) : null}
                  </div>
                ),
              },
            ]}
          />
        )}
      </Card>
    </>
  );
}

function PlatformSupport() {
  const qc = useQueryClient();
  const toast = useToast();
  const list = useQuery({
    queryKey: ['admin', 'support'],
    queryFn: () => call<{ data: Grant[] }>('GET', '/admin/support-access').then((r) => r.data),
    refetchInterval: 20_000,
  });
  const tenants = useQuery({
    queryKey: ['admin', 'tenants', 'all'],
    queryFn: () =>
      call<Paginated<TenantRow>>('GET', '/admin/tenants', undefined, { per_page: 100 }).then((r) => r.data),
  });
  const names = new Map((tenants.data ?? []).map((t) => [t.id, t.trading_name]));
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ tenant_id: '', reason: '', minutes: 60 });
  const [error, setError] = useState<string | null>(null);
  const ask = useMutation({
    mutationFn: () => call('POST', '/admin/support-access', form),
    onSuccess: () => {
      toast('Request sent — the owner will be asked to approve');
      setOpen(false);
      void qc.invalidateQueries({ queryKey: ['admin', 'support'] });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    ask.mutate();
  };

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Support access"
        description="Ask a provider for a short, read-only window. Once approved, open their space with the provider switcher."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>
            Request access
          </Button>
        }
      />
      <Card>
        {list.isPending ? (
          <Skeleton className="h-32" />
        ) : (
          <Table
            rows={list.data ?? []}
            rowKey={(g) => g.id}
            empty={<EmptyState icon={<LifeBuoy className="size-5" />} title="No requests yet" />}
            columns={[
              {
                key: 'tenant',
                header: 'Provider',
                cell: (g) => <span className="font-medium">{names.get(g.tenant_id) ?? g.tenant_id}</span>,
              },
              { key: 'reason', header: 'Reason', cell: (g) => g.reason },
              { key: 'status', header: 'Status', cell: (g) => <StatusBadge status={g.status} /> },
              {
                key: 'ends',
                header: 'Ends',
                cell: (g) => (
                  <span className="text-muted">{g.ends_at ? new Date(g.ends_at).toLocaleString() : '—'}</span>
                ),
              },
            ]}
          />
        )}
      </Card>
      <Dialog
        open={open}
        onOpenChange={setOpen}
        title="Request support access"
        description="The provider's owner approves or declines. You will have read-only access for the window."
      >
        <form onSubmit={submit} className="grid gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Select
            label="Provider"
            required
            value={form.tenant_id}
            onChange={(e) => setForm({ ...form, tenant_id: e.target.value })}
          >
            <option value="">Choose…</option>
            {(tenants.data ?? []).map((t) => (
              <option key={t.id} value={t.id}>
                {t.trading_name}
              </option>
            ))}
          </Select>
          <Field
            label="Reason (the owner sees this)"
            required
            value={form.reason}
            onChange={(e) => setForm({ ...form, reason: e.target.value })}
            placeholder="Learner cannot open their course"
          />
          <Select
            label="Window"
            value={String(form.minutes)}
            onChange={(e) => setForm({ ...form, minutes: Number(e.target.value) })}
          >
            {[15, 30, 60, 120, 240].map((m) => (
              <option key={m} value={m}>
                {m < 60 ? `${m} minutes` : `${m / 60} hour${m > 60 ? 's' : ''}`}
              </option>
            ))}
          </Select>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button type="submit" loading={ask.isPending}>
              Send request
            </Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}
