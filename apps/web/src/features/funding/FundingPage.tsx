import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { AgreementDialog } from './AgreementDialog';
import type { Agreement, Tranche } from './types';

/** Grant agreements for a cohort and how far each tranche is. */
export function FundingPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/funding' });
  const manage = useCan()('funding.manage');
  const [adding, setAdding] = useState(false);
  const q = useQuery({
    queryKey: ['grants', id],
    queryFn: () =>
      call<{ data: (Agreement & { tranches: Tranche[] })[] }>('GET', `/cohorts/${id}/grant-agreements`).then(
        (r) => r.data,
      ),
  });
  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Cohort
      </Link>
      <PageHeader
        title="Funding"
        description="Grant agreements for this cohort. Each tranche shows which learners meet its milestones, worked out from attendance, logbooks, results and the QCTO file."
        actions={manage ? <Button onClick={() => setAdding(true)}>Add a grant agreement</Button> : null}
      />
      {q.isPending ? <Skeleton className="h-48" /> : null}
      {q.isError ? <Alert tone="danger">{errorMessage(q.error)}</Alert> : null}
      {q.data?.length === 0 ? (
        <Card>
          <EmptyState title="No grant agreements yet">
            Add the SETA or employer agreement that funds these learners.
          </EmptyState>
        </Card>
      ) : null}
      <div className="grid gap-4">
        {q.data?.map((a) => (
          <Card key={a.id}>
            <CardHeader
              title={`${a.funder?.name ?? 'Funder'} · ${a.reference}`}
              action={
                <Link
                  to="/grant-agreements/$id"
                  params={{ id: a.id }}
                  className="text-small font-medium text-primary-700 hover:underline"
                >
                  Learners and evidence<span className="sr-only"> for {a.reference}</span>
                </Link>
              }
            />
            <p className="mb-3 text-small text-muted">
              {a.active_learners} of {a.learners} learners · {money(a.amount_per_learner_cents)} each ·{' '}
              {money(a.total_cents)} in total · {shortDate(a.starts_on)} to {shortDate(a.ends_on)}
            </p>
            <TrancheTable tranches={a.tranches} />
          </Card>
        ))}
      </div>
      {adding ? <AgreementDialog cohortId={id} onClose={() => setAdding(false)} /> : null}
    </>
  );
}

export function TrancheTable({ tranches }: { tranches: Tranche[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-small">
        <thead className="text-muted">
          <tr>
            <th scope="col" className="py-2 pr-3 font-medium">
              Tranche
            </th>
            <th scope="col" className="py-2 pr-3 font-medium">
              Milestones
            </th>
            <th scope="col" className="py-2 pr-3 font-medium">
              Learners met
            </th>
            <th scope="col" className="py-2 pr-3 font-medium">
              Claimable
            </th>
            <th scope="col" className="py-2 font-medium">
              Status
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {tranches.map((t) => (
            <tr key={t.id} className="align-top">
              <td className="py-2 pr-3 text-ink">
                {t.number} · {t.share_pct}%
                {t.due_on ? <span className="block text-muted">due {shortDate(t.due_on)}</span> : null}
              </td>
              <td className="py-2 pr-3">
                <ul className="grid gap-0.5">
                  {t.milestones.map((m) => (
                    <li key={m.key}>{m.label}</li>
                  ))}
                  {t.evidence.map((e, i) => (
                    <li key={i} className={e.file_id ? '' : 'text-warning'}>
                      {e.title}
                      {e.file_id ? '' : ' (not uploaded)'}
                    </li>
                  ))}
                </ul>
              </td>
              <td className="py-2 pr-3 text-ink">
                {t.learners_met} of {t.learners_total}
              </td>
              <td className="py-2 pr-3 text-ink">{money(t.claimable_cents)}</td>
              <td className="py-2">
                {t.ready ? <Badge tone="success">Ready to claim</Badge> : <Badge>Not ready</Badge>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
