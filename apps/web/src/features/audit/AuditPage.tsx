import { useInfiniteQuery } from '@tanstack/react-query';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, EmptyState, Field, PageHeader, Skeleton, Table } from '@/components/ui';
import { call } from '@/lib/api';
import { useMe } from '@/lib/session';

interface Entry {
  id: string;
  tenant_id: string | null;
  actor_id: string | null;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  changes: Record<string, unknown> | null;
  meta: Record<string, unknown> | null;
  ip: string | null;
  created_at: string;
}

/** Audit trail: the provider sees its own, the platform sees all. Cursor-paginated. */
export function AuditPage() {
  const me = useMe();
  const path = me.tenant ? '/audit-logs' : '/admin/audit-logs';
  const [action, setAction] = useState('');
  const [open, setOpen] = useState<string | null>(null);

  const q = useInfiniteQuery({
    queryKey: ['audit', path, action],
    initialPageParam: undefined as string | undefined,
    queryFn: ({ pageParam }) =>
      call<{ data: Entry[]; next_cursor: string | null }>('GET', path, undefined, {
        action: action || undefined,
        cursor: pageParam,
        per_page: 50,
      }),
    getNextPageParam: (last) => last.next_cursor ?? undefined,
  });
  const rows = q.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <>
      <PageHeader
        eyebrow="Compliance"
        title="Audit log"
        description="Every change, sign-in and support visit — append-only and kept for audits by QCTO, SETAs and your own team."
      />
      <Card>
        <div className="mb-4 max-w-sm">
          <Field
            label="Filter by action"
            placeholder="e.g. tenant., auth.signed_in"
            value={action}
            onChange={(e) => setAction(e.target.value)}
          />
        </div>
        {q.isPending ? (
          <div className="grid gap-2">
            {Array.from({ length: 6 }, (_, i) => (
              <Skeleton key={i} className="h-10" />
            ))}
          </div>
        ) : (
          <Table
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => setOpen(open === r.id ? null : r.id)}
            empty={<EmptyState icon={<ScrollText className="size-5" />} title="No entries in the last 30 days" />}
            columns={[
              {
                key: 'when',
                header: 'When',
                cell: (r) => (
                  <span className="whitespace-nowrap tabular-nums text-muted">
                    {new Date(r.created_at).toLocaleString()}
                  </span>
                ),
              },
              {
                key: 'action',
                header: 'Action',
                cell: (r) => (
                  <div>
                    <code className="font-mono text-[12px] text-ink">{r.action}</code>
                    {r.meta?.support_grant ? (
                      <Badge tone="info">support</Badge>
                    ) : r.meta?.platform ? (
                      <Badge tone="primary">platform</Badge>
                    ) : null}
                    {open === r.id && r.changes ? (
                      <pre className="mt-2 max-w-xl overflow-x-auto rounded-md bg-surface-muted p-2 text-[11.5px]">
                        {JSON.stringify(r.changes, null, 2)}
                      </pre>
                    ) : null}
                  </div>
                ),
              },
              {
                key: 'subject',
                header: 'Subject',
                cell: (r) => <span className="text-muted">{r.subject_type?.split('\\').pop() ?? '—'}</span>,
              },
              {
                key: 'ip',
                header: 'IP',
                cell: (r) => <span className="font-mono text-[12px] text-muted">{r.ip ?? '—'}</span>,
              },
            ]}
          />
        )}
        {q.hasNextPage ? (
          <div className="mt-4 flex justify-center">
            <Button variant="secondary" loading={q.isFetchingNextPage} onClick={() => void q.fetchNextPage()}>
              Load more
            </Button>
          </div>
        ) : null}
      </Card>
    </>
  );
}
