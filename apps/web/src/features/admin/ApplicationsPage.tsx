import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { Inbox } from 'lucide-react';
import { useState } from 'react';
import { Card, EmptyState, PageHeader, Skeleton, StatusBadge, Table, cx } from '@/components/ui';
import { call } from '@/lib/api';

interface Row {
  id: string;
  reference: string;
  status: string;
  legal_name: string;
  contact: string;
  email: string;
  sdp_number: string | null;
  province: string | null;
  submitted_at: string | null;
  created_at: string;
}

const filters = [
  ['submitted', 'To review'],
  ['info_requested', 'Waiting on applicant'],
  ['draft', 'In progress'],
  ['approved', 'Approved'],
  ['declined', 'Declined'],
  ['all', 'All'],
] as const;

const when = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' }) : '—';

/** Applications to join, oldest submitted first. */
export function ApplicationsPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<(typeof filters)[number][0]>('submitted');
  const q = useQuery({
    queryKey: ['admin', 'applications', status],
    queryFn: () =>
      call<{ data: Row[]; counts: Record<string, number> }>('GET', '/admin/applications', undefined, { status }),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Applications"
        description="Providers applying to join. Automatic checks run as they apply; you confirm against CIPC and the QCTO letter, then approve in one step."
      />
      <Card>
        <div className="mb-4 flex gap-1 overflow-x-auto rounded-lg bg-line-soft p-1">
          {filters.map(([key, label]) => (
            <button
              key={key}
              type="button"
              aria-pressed={status === key}
              onClick={() => setStatus(key)}
              className={cx(
                'shrink-0 rounded-md px-3 py-1 text-small',
                status === key ? 'bg-surface text-ink shadow-sm' : 'text-muted',
              )}
            >
              {label}
              {key !== 'all' && q.data?.counts[key] ? (
                <span className="ml-1.5 tabular-nums text-faint">{q.data.counts[key]}</span>
              ) : null}
            </button>
          ))}
        </div>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(r) => r.id}
            onRowClick={(r) => void navigate({ to: '/applications/$id', params: { id: r.id } })}
            empty={<EmptyState icon={<Inbox className="size-5" />} title="Nothing here" />}
            columns={[
              {
                key: 'org',
                header: 'Organisation',
                cell: (r) => (
                  <div className="min-w-0">
                    <div className="truncate font-medium text-ink">{r.legal_name}</div>
                    <div className="truncate text-small text-muted">
                      {r.contact} · {r.email}
                    </div>
                  </div>
                ),
              },
              { key: 'ref', header: 'Reference', cell: (r) => <span className="tabular-nums">{r.reference}</span> },
              { key: 'sdp', header: 'SDP number', cell: (r) => r.sdp_number ?? '—' },
              { key: 'province', header: 'Province', cell: (r) => r.province ?? '—' },
              { key: 'submitted', header: 'Submitted', cell: (r) => when(r.submitted_at) },
              { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
        )}
      </Card>
    </>
  );
}
