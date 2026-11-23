import { useQuery } from '@tanstack/react-query';
import { Alert, Badge, Card, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { type ModuleResults, methodLabel, stateBadge } from './types';

/** The learner's results per module, with feedback and what to work on. */
export function MyResultsPage() {
  const q = useQuery({
    queryKey: ['my-results'],
    queryFn: () =>
      call<{ data: { enrolment_id: string; cohort: string | null; modules: ModuleResults[] }[] }>(
        'GET',
        '/me/results',
      ).then((r) => r.data),
  });
  return (
    <>
      <PageHeader
        title="My results"
        description="Your summative results per module. If you are not yet competent, read the feedback before your next attempt."
      />
      {q.isPending ? (
        <Skeleton className="h-40" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No courses">Results appear once you are enrolled.</EmptyState>
        </Card>
      ) : (
        q.data.map((c) => (
          <Card key={c.enrolment_id} className="mb-4">
            <h2 className="mb-2 text-[15px] font-semibold text-ink">{c.cohort}</h2>
            <ul className="divide-y divide-line">
              {c.modules.map((m) => (
                <li key={m.module_id} className="grid gap-1 py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-medium text-ink">
                      {m.code} {m.title}
                    </p>
                    <Badge tone={stateBadge[m.state].tone}>{stateBadge[m.state].label}</Badge>
                  </div>
                  {m.instruments
                    .filter((i) => i.latest)
                    .map((i) => (
                      <div key={i.instrument_id} className="text-small">
                        <p className="text-muted">
                          {i.title} ({methodLabel[i.method]}) · attempt {i.latest!.attempt} ·{' '}
                          {shortDate(i.latest!.assessed_on)}
                          {i.latest!.score !== null ? ` · ${i.latest!.score}%` : ''}
                        </p>
                        {i.latest!.feedback ? <p className="text-ink">{i.latest!.feedback}</p> : null}
                        {i.latest!.remediation ? <p className="text-ink">To work on: {i.latest!.remediation}</p> : null}
                      </div>
                    ))}
                </li>
              ))}
            </ul>
          </Card>
        ))
      )}
    </>
  );
}
