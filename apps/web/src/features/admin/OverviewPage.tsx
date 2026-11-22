import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Building2, CircleDashed, PauseCircle, Server, Users } from 'lucide-react';
import { Card, CardHeader, EmptyState, Kpi, PageHeader, Skeleton, StatusBadge, Table } from '@/components/ui';
import { call } from '@/lib/api';
import { useMe } from '@/lib/session';
import type { Paginated, TenantRow } from './types';

interface Overview {
  tenants: Record<string, number>;
  users: number;
  cells: number;
}

export function PlatformOverviewPage() {
  const me = useMe();
  const overview = useQuery({
    queryKey: ['admin', 'overview'],
    queryFn: () => call<Overview>('GET', '/admin/overview'),
    refetchInterval: 30_000,
  });
  const pending = useQuery({
    queryKey: ['admin', 'tenants', 'pending'],
    queryFn: () => call<Paginated<TenantRow>>('GET', '/admin/tenants', undefined, { status: 'pending', per_page: 5 }),
  });
  const t = overview.data?.tenants;
  const total = t ? Object.values(t).reduce((a, b) => a + b, 0) : 0;

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title={`Good ${greeting()}, ${me.user.first_name}`}
        description="Live view of training providers, people and cells across the platform."
      />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {overview.isPending ? (
          Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-[104px] rounded-xl" />)
        ) : (
          <>
            <Kpi
              label="Training providers"
              value={total}
              icon={<Building2 className="size-4" />}
              hint={`${t?.active ?? 0} active`}
            />
            <Kpi
              label="Awaiting approval"
              value={(t?.pending ?? 0) + (t?.verified ?? 0)}
              icon={<CircleDashed className="size-4" />}
            />
            <Kpi label="Suspended" value={t?.suspended ?? 0} icon={<PauseCircle className="size-4" />} />
            <Kpi
              label="People"
              value={overview.data?.users ?? 0}
              icon={<Users className="size-4" />}
              hint={`${overview.data?.cells ?? 0} cell(s)`}
            />
          </>
        )}
      </div>

      <Card className="mt-5">
        <CardHeader
          title="Waiting for approval"
          subtitle="Check documents, then approve to go live."
          action={
            <Link to="/tenants" className="text-small font-medium text-primary-700 hover:underline">
              All providers
            </Link>
          }
        />
        <Table
          rows={pending.data?.data ?? []}
          rowKey={(r) => r.id}
          empty={
            <EmptyState icon={<Server className="size-5" />} title="Nothing waiting">
              New applications will appear here.
            </EmptyState>
          }
          columns={[
            {
              key: 'name',
              header: 'Provider',
              cell: (r) => (
                <Link to="/tenants/$id" params={{ id: r.id }} className="font-medium hover:text-primary-700">
                  {r.trading_name}
                </Link>
              ),
            },
            { key: 'code', header: 'Code', cell: (r) => <code className="text-small">{r.code}</code> },
            { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
            {
              key: 'created',
              header: 'Applied',
              cell: (r) => (
                <span className="text-muted">{r.created_at ? new Date(r.created_at).toLocaleDateString() : '—'}</span>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}

export function greeting(): string {
  const h = new Date().getHours();
  return h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening';
}
