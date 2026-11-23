import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { MapPin } from 'lucide-react';
import { Alert, Badge, Card, EmptyState, PageHeader, Skeleton } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { clockOf, dateOf, type ClassSession } from './TimetablePage';

type Mine = ClassSession & { cohort: string | null; teaching: boolean };

const longDay = (ymd: string) =>
  new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-ZA', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  });

/** The facilitator's classes for the next two weeks: open one to show the check-in code and mark attendance. */
export function ClassesPage() {
  const q = useQuery({
    queryKey: ['me', 'timetable'],
    queryFn: () => call<{ data: Mine[] }>('GET', '/me/timetable').then((r) => r.data.filter((s) => s.teaching)),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const days = [...new Set(q.data.map((s) => dateOf(s.starts_at)))];

  return (
    <>
      <PageHeader
        title="My classes"
        description="Your classes for the next two weeks. Open a class to show the check-in code and mark attendance."
      />
      {q.data.length === 0 ? (
        <EmptyState title="No classes in the next two weeks">
          Classes you facilitate appear here once they are on a timetable.
        </EmptyState>
      ) : (
        <div className="grid gap-4">
          {days.map((d) => (
            <Card key={d}>
              <h2 className="mb-2 text-small font-semibold text-muted">{longDay(d)}</h2>
              <ul className="grid gap-2">
                {q.data
                  .filter((s) => dateOf(s.starts_at) === d)
                  .map((s) => (
                    <li key={s.id}>
                      <Link
                        to="/classes/$id"
                        params={{ id: s.id }}
                        className="grid gap-1 rounded-lg border border-line p-3 hover:border-primary-200"
                      >
                        <span className="flex flex-wrap items-center justify-between gap-2">
                          <span className="font-medium text-ink">
                            {clockOf(s.starts_at)}–{clockOf(s.ends_at)} · {s.title ?? 'Class'}
                          </span>
                          {s.status === 'cancelled' ? <Badge tone="neutral">Cancelled</Badge> : null}
                        </span>
                        <span className="flex flex-wrap gap-x-4 text-small text-muted">
                          <span>{s.cohort}</span>
                          <span className="inline-flex items-center gap-1">
                            <MapPin className="size-3.5" aria-hidden /> {s.room?.name ?? s.venue ?? 'No venue'}
                          </span>
                        </span>
                      </Link>
                    </li>
                  ))}
              </ul>
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
