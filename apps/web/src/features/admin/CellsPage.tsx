import { useQuery } from '@tanstack/react-query';
import { Server } from 'lucide-react';
import { Badge, Card, EmptyState, PageHeader, Skeleton, StatusBadge, Table } from '@/components/ui';
import { call } from '@/lib/api';

interface Cell {
  id: string;
  name: string;
  region: string;
  status: string;
  dedicated: boolean;
  tenants: number;
  capacity: number;
  load: number;
}

export function CellsPage() {
  const cells = useQuery({
    queryKey: ['admin', 'cells'],
    queryFn: () => call<{ data: Cell[] }>('GET', '/admin/cells').then((r) => r.data),
    refetchInterval: 30_000,
  });

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Cells"
        description="Independent stacks that each serve a group of providers. New providers go to the least-loaded shared cell; large clients can have their own."
      />
      <Card>
        {cells.isPending ? (
          <Skeleton className="h-32" />
        ) : (
          <Table
            rows={cells.data ?? []}
            rowKey={(c) => c.id}
            empty={<EmptyState icon={<Server className="size-5" />} title="No cells" />}
            columns={[
              {
                key: 'name',
                header: 'Cell',
                cell: (c) => (
                  <div>
                    <div className="font-medium">
                      {c.name} {c.dedicated ? <Badge tone="info">dedicated</Badge> : null}
                    </div>
                    <code className="text-small text-muted">{c.id}</code>
                  </div>
                ),
              },
              { key: 'region', header: 'Region', cell: (c) => c.region },
              {
                key: 'load',
                header: 'Load',
                cell: (c) => (
                  <div className="flex min-w-40 items-center gap-3">
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-line-soft">
                      <div
                        className={`h-full rounded-full ${c.load > 0.85 ? 'bg-danger' : c.load > 0.6 ? 'bg-warning' : 'bg-primary-600'}`}
                        style={{ width: `${Math.min(100, c.load * 100)}%` }}
                      />
                    </div>
                    <span className="text-small tabular-nums text-muted">
                      {c.tenants}/{c.capacity}
                    </span>
                  </div>
                ),
              },
              { key: 'status', header: 'Status', cell: (c) => <StatusBadge status={c.status} /> },
            ]}
          />
        )}
      </Card>
    </>
  );
}
