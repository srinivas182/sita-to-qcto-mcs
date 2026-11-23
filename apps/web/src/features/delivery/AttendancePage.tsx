import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Dialog, Field, PageHeader, Skeleton, Table } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';

interface Row {
  user_id: string;
  name: string | null;
  held: number;
  attended: number;
  excused: number;
  absent: number;
  late: number;
  pct: number | null;
  at_risk: boolean;
  to_review: number;
}
interface Report {
  learners: Row[];
  sessions: number;
  from: string;
  to: string;
  threshold_pct: number;
}
interface Settings {
  radius_m: number;
  late_after_minutes: number;
  threshold_pct: number;
  offline_sync_hours: number;
}

/** Attendance per learner for a cohort: classes held, attended, excused, the percentage and who is at risk. */
export function AttendancePage() {
  const { id } = useParams({ from: '/app/cohorts/$id/attendance' });
  const manage = useCan()('cohorts.manage');
  const [range, setRange] = useState<{ from: string; to: string } | null>(null);
  const [settings, setSettings] = useState(false);
  const q = useQuery({
    queryKey: ['attendance', id, range],
    queryFn: () =>
      call<{ data: Report }>('GET', `/cohorts/${id}/attendance`, undefined, range ?? undefined).then((r) => r.data),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const r = q.data;
  const risky = r.learners.filter((l) => l.at_risk).length;

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
        title="Attendance"
        description={`Classes that have started, from ${r.from} to ${r.to}. Excused absences are left out of the percentage. Below ${r.threshold_pct}% is at risk.`}
        actions={
          manage ? (
            <Button variant="secondary" onClick={() => setSettings(true)}>
              Attendance rules
            </Button>
          ) : null
        }
      />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <Field
          label="From"
          type="date"
          value={range?.from ?? r.from}
          onChange={(e) => setRange({ from: e.target.value, to: range?.to ?? r.to })}
        />
        <Field
          label="To"
          type="date"
          value={range?.to ?? r.to}
          onChange={(e) => setRange({ from: range?.from ?? r.from, to: e.target.value })}
        />
      </div>
      {risky ? (
        <div className="mb-4">
          <Alert tone="warning">
            {risky} {risky === 1 ? 'learner is' : 'learners are'} below {r.threshold_pct}%.
          </Alert>
        </div>
      ) : null}
      <Card>
        <Table
          rows={r.learners}
          rowKey={(l) => l.user_id}
          empty="No learners are enrolled yet."
          columns={[
            { key: 'name', header: 'Learner', cell: (l) => <span className="font-medium text-ink">{l.name}</span> },
            { key: 'held', header: 'Classes', cell: (l) => <span className="tabular-nums">{l.held}</span> },
            { key: 'attended', header: 'Attended', cell: (l) => <span className="tabular-nums">{l.attended}</span> },
            { key: 'excused', header: 'Excused', cell: (l) => <span className="tabular-nums">{l.excused}</span> },
            { key: 'absent', header: 'Absent', cell: (l) => <span className="tabular-nums">{l.absent}</span> },
            { key: 'late', header: 'Late', cell: (l) => <span className="tabular-nums">{l.late}</span> },
            {
              key: 'pct',
              header: 'Attendance',
              cell: (l) =>
                l.pct === null ? (
                  <span className="text-muted">—</span>
                ) : (
                  <Badge tone={l.at_risk ? 'danger' : 'success'} dot>
                    {l.pct}%
                  </Badge>
                ),
            },
            {
              key: 'review',
              header: 'To review',
              cell: (l) =>
                l.to_review ? <Badge tone="warning">{l.to_review}</Badge> : <span className="text-muted">—</span>,
            },
          ]}
        />
      </Card>
      {settings ? <SettingsDialog onClose={() => setSettings(false)} /> : null}
    </>
  );
}

function SettingsDialog({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['delivery-settings'],
    queryFn: () => call<{ data: Settings }>('GET', '/delivery-settings').then((r) => r.data),
  });
  const [draft, setDraft] = useState<Partial<Settings>>({});
  const v = { ...(q.data ?? ({} as Settings)), ...draft };
  const save = useMutation({
    mutationFn: () => call('PATCH', '/delivery-settings', draft),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['delivery-settings'] });
      void qc.invalidateQueries({ queryKey: ['attendance'] });
      onClose();
    },
  });
  const num = (k: keyof Settings) => (e: { target: { value: string } }) =>
    setDraft({ ...draft, [k]: Number(e.target.value) });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title="Attendance rules">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field
          label="Check-in distance from the centre (m)"
          type="number"
          min={50}
          max={2000}
          value={v.radius_m ?? ''}
          onChange={num('radius_m')}
        />
        <Field
          label="Late after (minutes)"
          type="number"
          min={0}
          max={120}
          value={v.late_after_minutes ?? ''}
          onChange={num('late_after_minutes')}
        />
        <Field
          label="At risk below (%)"
          type="number"
          min={50}
          max={100}
          value={v.threshold_pct ?? ''}
          onChange={num('threshold_pct')}
        />
        <Field
          label="Offline check-ins accepted for (hours)"
          type="number"
          min={12}
          max={168}
          value={v.offline_sync_hours ?? ''}
          onChange={num('offline_sync_hours')}
        />
      </div>
      {save.isError ? <p className="mt-2 text-small text-danger">{errorMessage(save.error)}</p> : null}
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Cancel
        </Button>
        <Button loading={save.isPending} disabled={!Object.keys(draft).length} onClick={() => save.mutate()}>
          Save
        </Button>
      </div>
    </Dialog>
  );
}
