import { useQuery } from '@tanstack/react-query';
import { MessageSquareText } from 'lucide-react';
import { useState } from 'react';
import { Badge, Card, CardHeader, EmptyState, Field, PageHeader, Select, Skeleton, Table } from '@/components/ui';
import { call } from '@/lib/api';
import { money } from '@/lib/money';

interface Row {
  id: string;
  tenant: string | null;
  channel: 'sms' | 'whatsapp';
  driver: string;
  purpose: string;
  to: string;
  status: 'sent' | 'delivered' | 'read' | 'failed' | 'undelivered';
  cost_cents: number | null;
  error: string | null;
  fallback: boolean;
  created_at: string;
}
interface Summary {
  by_driver: { driver: string; channel: string; sent: number; delivered: number; failed: number; cost_cents: number }[];
  by_tenant: { tenant: string | null; sent: number; cost_cents: number }[];
}

const tone = { sent: 'info', delivered: 'success', read: 'success', failed: 'danger', undelivered: 'danger' } as const;
const name = (driver: string) => driver.split('.')[1]!.replace(/^\w/, (c) => c.toUpperCase());

/** Super Admin: every SMS and WhatsApp message, delivery and cost by provider and by training provider. */
export function MessagesPage() {
  const [filters, setFilters] = useState({ channel: '', status: '', phone: '' });
  const summary = useQuery({
    queryKey: ['admin', 'messages', 'summary'],
    queryFn: () => call<Summary>('GET', '/admin/messages/summary'),
  });
  const list = useQuery({
    queryKey: ['admin', 'messages', filters],
    queryFn: () =>
      call<{ data: Row[] }>('GET', '/admin/messages', undefined, {
        channel: filters.channel || undefined,
        status: filters.status || undefined,
        phone: filters.phone.length >= 9 ? filters.phone : undefined,
      }).then((r) => r.data),
  });

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Messages"
        description="Sign-in codes and notifications by SMS and WhatsApp this month: delivery and cost per provider and per training provider. Message text is never stored."
      />
      <div className="mb-5 grid gap-5 xl:grid-cols-2">
        <Card>
          <CardHeader title="By provider" subtitle="This month" />
          {summary.isPending ? (
            <Skeleton className="h-28" />
          ) : (summary.data?.by_driver.length ?? 0) === 0 ? (
            <p className="text-small text-muted">No messages yet this month.</p>
          ) : (
            <Table
              rows={summary.data!.by_driver}
              rowKey={(r) => r.driver}
              columns={[
                { key: 'd', header: 'Provider', cell: (r) => <span className="font-medium">{name(r.driver)}</span> },
                { key: 's', header: 'Sent', cell: (r) => r.sent },
                {
                  key: 'ok',
                  header: 'Delivered',
                  cell: (r) => (r.sent ? `${Math.round((r.delivered / r.sent) * 100)}%` : '—'),
                },
                { key: 'f', header: 'Failed', cell: (r) => r.failed },
                { key: 'c', header: 'Cost', cell: (r) => money(r.cost_cents) },
              ]}
            />
          )}
        </Card>
        <Card>
          <CardHeader title="By training provider" subtitle="For re-billing message costs" />
          {summary.isPending ? (
            <Skeleton className="h-28" />
          ) : (
            <Table
              rows={summary.data?.by_tenant ?? []}
              rowKey={(r) => r.tenant ?? 'platform'}
              columns={[
                { key: 't', header: 'Provider', cell: (r) => r.tenant ?? 'Platform' },
                { key: 's', header: 'Messages', cell: (r) => r.sent },
                { key: 'c', header: 'Cost', cell: (r) => money(r.cost_cents) },
              ]}
            />
          )}
        </Card>
      </div>
      <Card>
        <div className="mb-4 flex flex-wrap gap-3">
          <Select
            label="Channel"
            value={filters.channel}
            onChange={(e) => setFilters({ ...filters, channel: e.target.value })}
          >
            <option value="">All</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="sms">SMS</option>
          </Select>
          <Select
            label="Status"
            value={filters.status}
            onChange={(e) => setFilters({ ...filters, status: e.target.value })}
          >
            <option value="">All</option>
            <option value="sent">Sent</option>
            <option value="delivered">Delivered</option>
            <option value="failed">Failed</option>
          </Select>
          <Field
            label="Find by phone number"
            type="tel"
            inputMode="tel"
            value={filters.phone}
            onChange={(e) => setFilters({ ...filters, phone: e.target.value })}
            placeholder="082 123 4567"
          />
        </div>
        {list.isPending ? (
          <Skeleton className="h-40" />
        ) : (list.data ?? []).length === 0 ? (
          <EmptyState icon={<MessageSquareText className="size-6" />} title="No messages">
            Messages appear here as sign-in codes and notifications are sent.
          </EmptyState>
        ) : (
          <Table
            rows={list.data!}
            rowKey={(r) => r.id}
            columns={[
              { key: 'w', header: 'When', cell: (r) => new Date(r.created_at).toLocaleString('en-ZA') },
              {
                key: 'p',
                header: 'Provider',
                cell: (r) => (
                  <span>
                    {name(r.driver)}
                    {r.fallback ? <span className="ml-1 text-caption text-muted">(fallback)</span> : null}
                  </span>
                ),
              },
              { key: 't', header: 'To', cell: (r) => <span className="font-mono text-small">{r.to}</span> },
              {
                key: 'u',
                header: 'For',
                cell: (r) => (
                  <span className="text-small">
                    {r.purpose === 'otp' ? 'Sign-in code' : r.purpose}
                    {r.tenant ? ` · ${r.tenant}` : ''}
                  </span>
                ),
              },
              {
                key: 's',
                header: 'Status',
                cell: (r) => (
                  <span title={r.error ?? undefined}>
                    <Badge tone={tone[r.status]}>{r.status}</Badge>
                  </span>
                ),
              },
              { key: 'c', header: 'Cost', cell: (r) => (r.cost_cents != null ? money(r.cost_cents) : '—') },
            ]}
          />
        )}
      </Card>
    </>
  );
}
