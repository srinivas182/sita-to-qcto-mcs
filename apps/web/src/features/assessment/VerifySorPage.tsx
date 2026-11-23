import { useQuery } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { Alert, Badge, Card, Skeleton } from '@/components/ui';
import { PublicLayout } from '@/features/agreements/PublicLayout';
import { call } from '@/lib/api';
import { shortDate } from '@/lib/money';

interface Verification {
  number: string;
  status: 'issued' | 'withdrawn';
  issued_at: string;
  withdrawn_at: string | null;
  provider: string | null;
  learner: string | null;
  id_number: string | null;
  qualification: string | null;
  saqa_id: string | null;
  modules: number;
  intact: boolean;
}

/** Public: check that a Statement of Results is genuine and still stands. */
export function VerifySorPage() {
  const { code } = useParams({ strict: false }) as { code: string };
  const q = useQuery({
    queryKey: ['verify-sor', code],
    queryFn: () => call<{ data: Verification }>('GET', `/public/sor/${code}`).then((r) => r.data),
    retry: false,
  });
  if (q.isPending)
    return (
      <PublicLayout>
        <Skeleton className="h-48" />
      </PublicLayout>
    );
  if (q.isError)
    return (
      <PublicLayout>
        <Card>
          <h1 className="text-[20px] font-semibold">We can't find this statement</h1>
          <p className="mt-2 text-muted">Check the link printed at the bottom of the Statement of Results.</p>
        </Card>
      </PublicLayout>
    );
  const v = q.data;
  const good = v.status === 'issued' && v.intact;
  return (
    <PublicLayout>
      <div>
        <p className="text-small text-muted">Statement of Results check · {v.provider}</p>
        <h1 className="text-[22px] font-semibold tracking-tight text-ink">{v.number}</h1>
        <div className="mt-2">
          <Badge tone={good ? 'success' : 'danger'} dot>
            {v.status === 'withdrawn' ? 'Withdrawn' : v.intact ? 'Genuine and valid' : 'Record changed'}
          </Badge>
        </div>
      </div>
      {v.status === 'withdrawn' ? (
        <Alert tone="danger">
          The provider withdrew this statement{v.withdrawn_at ? ` on ${shortDate(v.withdrawn_at)}` : ''}. Ask them for
          the current one.
        </Alert>
      ) : null}
      {!v.intact ? (
        <Alert tone="danger">The stored record no longer matches what was issued. Contact the provider.</Alert>
      ) : null}
      <Card>
        <dl className="grid gap-2 text-body sm:grid-cols-[12rem_1fr]">
          <dt className="text-muted">Learner</dt>
          <dd className="text-ink">
            {v.learner} ({v.id_number})
          </dd>
          <dt className="text-muted">Qualification</dt>
          <dd className="text-ink">
            {v.qualification} · SAQA ID {v.saqa_id}
          </dd>
          <dt className="text-muted">Modules competent</dt>
          <dd className="text-ink">{v.modules}</dd>
          <dt className="text-muted">Issued</dt>
          <dd className="text-ink">{shortDate(v.issued_at)}</dd>
        </dl>
      </Card>
    </PublicLayout>
  );
}
