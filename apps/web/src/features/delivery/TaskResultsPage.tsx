import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { Alert, Badge, Card, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

interface Results {
  tasks: { id: string; title: string; module: string | null }[];
  learners: {
    user_id: string;
    name: string | null;
    passed: number;
    results: Record<
      string,
      { status: 'submitted' | 'passed' | 'not_yet'; score_pct: number | null; attempts: number } | null
    >;
  }[];
}

const tone = { passed: 'success', not_yet: 'danger', submitted: 'warning' } as const;
const label = { passed: 'Passed', not_yet: 'Not yet', submitted: 'To mark' } as const;

/** Formative task results per learner: the best attempt for each task. */
export function TaskResultsPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/tasks' });
  const q = useQuery({
    queryKey: ['task-results', id],
    queryFn: () => call<{ data: Results }>('GET', `/cohorts/${id}/task-results`).then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const r = q.data;
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
        title="Formative task results"
        description="The best attempt per learner and task. These feed the Statement of Results."
      />
      <Card>
        {r.tasks.length === 0 || r.learners.length === 0 ? (
          <EmptyState title="No results yet">
            Results appear once tasks are published and learners answer them.
          </EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-small">
              <caption className="sr-only">Results per learner and task</caption>
              <thead>
                <tr className="border-b border-line text-left text-muted">
                  <th scope="col" className="py-2 pr-3 font-medium">
                    Learner
                  </th>
                  {r.tasks.map((t) => (
                    <th key={t.id} scope="col" className="px-2 py-2 font-medium">
                      {t.module ? `${t.module} · ` : ''}
                      {t.title}
                    </th>
                  ))}
                  <th scope="col" className="px-2 py-2 font-medium">
                    Passed
                  </th>
                </tr>
              </thead>
              <tbody>
                {r.learners.map((l) => (
                  <tr key={l.user_id} className="border-b border-line">
                    <th scope="row" className="py-2 pr-3 text-left font-medium text-ink">
                      {l.name}
                    </th>
                    {r.tasks.map((t) => {
                      const c = l.results[t.id];
                      return (
                        <td key={t.id} className="px-2 py-2">
                          {c ? (
                            <Badge tone={tone[c.status]}>
                              {label[c.status]}
                              {c.score_pct !== null ? ` ${c.score_pct}%` : ''}
                            </Badge>
                          ) : (
                            <span className="text-muted">—</span>
                          )}
                        </td>
                      );
                    })}
                    <td className="px-2 py-2 tabular-nums">
                      {l.passed} of {r.tasks.length}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
