import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { Alert, Badge, Card, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { type ModuleState, stateBadge } from './types';

interface Grid {
  modules: { id: string; code: string; title: string; kind: string }[];
  learners: {
    enrolment_id: string;
    user_id: string;
    name: string | null;
    modules: { module_id: string; state: ModuleState }[];
  }[];
}

/** Learners × modules for a cohort: Competent, Not yet competent, in progress or not assessed. */
export function CohortResultsPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/results' });
  const q = useQuery({
    queryKey: ['cohort-results', id],
    queryFn: () => call<{ data: Grid }>('GET', `/cohorts/${id}/results`).then((r) => r.data),
  });
  return (
    <>
      <Link to="/assessing" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Assessments
      </Link>
      <PageHeader
        title="Results"
        description="Open a learner to record a decision or see their attempts."
        actions={
          <Link
            to="/cohorts/$id/moderation"
            params={{ id }}
            className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-small font-medium text-ink hover:border-primary-200"
          >
            Moderation
          </Link>
        }
      />
      {q.isPending ? (
        <Skeleton className="h-64" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.learners.length === 0 ? (
        <Card>
          <EmptyState title="No learners">Learners appear once enrolled.</EmptyState>
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-small">
              <caption className="sr-only">Module results per learner</caption>
              <thead>
                <tr className="border-b border-line text-left text-muted">
                  <th scope="col" className="py-2 pr-3 font-medium">
                    Learner
                  </th>
                  {q.data.modules.map((m) => (
                    <th key={m.id} scope="col" className="px-2 py-2 font-medium" title={m.title}>
                      {m.code}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {q.data.learners.map((l) => (
                  <tr key={l.enrolment_id} className="border-b border-line">
                    <th scope="row" className="py-2 pr-3 text-left font-medium">
                      <Link
                        to="/cohorts/$id/results/$enrolment"
                        params={{ id, enrolment: l.enrolment_id }}
                        className="text-primary-700 hover:underline"
                      >
                        {l.name}
                      </Link>
                    </th>
                    {l.modules.map((m) => (
                      <td key={m.module_id} className="px-2 py-2">
                        <Badge tone={stateBadge[m.state].tone}>{stateBadge[m.state].label}</Badge>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  );
}
