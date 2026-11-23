import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Alert, Card, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

/** For assessors and moderators: the cohorts at their centres. */
export function AssessingPage() {
  const q = useQuery({
    queryKey: ['assessing'],
    queryFn: () =>
      call<{ data: { id: string; name: string; status: string; learners: number }[] }>('GET', '/me/assessing').then(
        (r) => r.data,
      ),
  });
  return (
    <>
      <PageHeader title="Assessments" description="Choose a cohort to record or check summative results." />
      {q.isPending ? (
        <Skeleton className="h-40" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No cohorts">Cohorts with learners at your centres appear here.</EmptyState>
        </Card>
      ) : (
        <Card>
          <ul className="divide-y divide-line">
            {q.data.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <Link
                  to="/cohorts/$id/results"
                  params={{ id: c.id }}
                  className="font-medium text-primary-700 hover:underline"
                >
                  {c.name}
                </Link>
                <span className="text-small text-muted">{c.learners} learners</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  );
}
