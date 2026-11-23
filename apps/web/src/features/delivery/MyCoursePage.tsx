import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, ExternalLink } from 'lucide-react';
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Skeleton, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { CourseTasks } from './DoTask';
import { megabytes } from './MaterialsPage';

interface Course {
  enrolment_id: string;
  status: string;
  cohort: string | null;
  starts_on: string | null;
  ends_on: string | null;
  qualification: { id: string; title: string } | null;
}
interface CourseMaterials {
  modules: { id: string; code: string; title: string }[];
  materials: {
    id: string;
    module_id: string | null;
    title: string;
    kind: string;
    url: string | null;
    size: number | null;
    low_size: number | null;
    completed: boolean;
  }[];
}

/** My course in the browser: only the courses I am enrolled on, and their materials. The app adds offline download. */
export function MyCoursePage() {
  const q = useQuery({
    queryKey: ['me', 'courses'],
    queryFn: () => call<{ data: Course[] }>('GET', '/me/courses').then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const active = q.data.filter((c) => c.status === 'active');

  return (
    <>
      <PageHeader
        title="My course"
        description="Materials for the course you are enrolled on. In the app you can also keep them on your phone for offline use."
      />
      {active.length === 0 ? (
        <Card>
          <EmptyState title="You are not enrolled on a course yet">Courses you are enrolled on appear here.</EmptyState>
        </Card>
      ) : (
        active.map((c) => <CourseCard key={c.enrolment_id} course={c} />)
      )}
    </>
  );
}

function CourseCard({ course }: { course: Course }) {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({
    queryKey: ['me', 'course', course.enrolment_id],
    queryFn: () =>
      call<{ data: CourseMaterials }>('GET', `/me/courses/${course.enrolment_id}/materials`).then((r) => r.data),
  });
  const done = useMutation({
    mutationFn: (v: { id: string; completed: boolean }) =>
      call('POST', `/me/materials/${v.id}/progress`, { completed: v.completed }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['me', 'course', course.enrolment_id] }),
  });
  const open = async (m: CourseMaterials['materials'][number]) => {
    try {
      if (m.kind === 'link' && m.url) {
        window.open(m.url, '_blank', 'noopener');
        return;
      }
      const r = await call<{ data: { url: string } }>(
        'GET',
        `/me/materials/${m.id}/download`,
        undefined,
        m.kind === 'video' && m.low_size ? { low: 1 } : undefined,
      );
      window.open(r.data.url, '_blank', 'noopener');
    } catch (e) {
      toast(errorMessage(e), 'error');
    }
  };
  const items = q.data?.materials ?? [];
  const finished = items.filter((m) => m.completed).length;

  return (
    <Card className="mb-4">
      <h2 className="text-body font-semibold text-ink">{course.qualification?.title ?? course.cohort}</h2>
      <p className="mb-3 text-small text-muted">
        {course.cohort} · {course.starts_on} to {course.ends_on}
        {items.length ? ` · ${finished} of ${items.length} done` : ''}
      </p>
      {q.isPending ? <Skeleton className="h-24" /> : null}
      {q.data && items.length === 0 ? (
        <p className="text-small text-muted">Your facilitator has not added materials yet.</p>
      ) : null}
      {q.data
        ? [
            ...q.data.modules.map((md) => ({ key: md.id, title: `${md.code} ${md.title}` })),
            { key: '', title: 'For the whole course' },
          ].map((g) => {
            const list = items.filter((m) => (m.module_id ?? '') === g.key);
            if (!list.length) return null;
            return (
              <section key={g.key || 'all'} className="mb-3">
                <h3 className="mb-1 text-small font-semibold text-muted">{g.title}</h3>
                <ul className="divide-y divide-line">
                  {list.map((m) => (
                    <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                      <span className="text-ink">
                        {m.title}{' '}
                        <span className="text-small text-muted">
                          {m.kind === 'video' && m.low_size
                            ? `· ${megabytes(m.low_size)} (low data)`
                            : m.size
                              ? `· ${megabytes(m.size)}`
                              : ''}
                        </span>
                      </span>
                      <span className="flex items-center gap-2">
                        {m.completed ? (
                          <Badge tone="success">
                            <CheckCircle2 className="size-3.5" aria-hidden /> Done
                          </Badge>
                        ) : (
                          <Button size="sm" variant="ghost" onClick={() => done.mutate({ id: m.id, completed: true })}>
                            Mark done<span className="sr-only"> {m.title}</span>
                          </Button>
                        )}
                        <Button
                          size="sm"
                          variant="secondary"
                          icon={<ExternalLink className="size-4" />}
                          onClick={() => void open(m)}
                        >
                          Open<span className="sr-only"> {m.title}</span>
                        </Button>
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            );
          })
        : null}
      <CourseTasks enrolmentId={course.enrolment_id} />
    </Card>
  );
}
