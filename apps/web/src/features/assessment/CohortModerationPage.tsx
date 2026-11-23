import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Dialog, EmptyState, PageHeader, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { downloadFrom } from '@/features/delivery/RegistersPage';

interface ModuleModeration {
  module_id: string;
  code: string;
  title: string;
  decisions: number;
  competent: number;
  nyc: number;
  sampled: number;
  moderated: number;
  overturned: number;
  policy_pct: number;
  compliant: boolean;
  assessors: {
    assessor_id: string;
    name: string | null;
    decisions: number;
    sampled: number;
    overturned: number;
    compliant: boolean;
  }[];
  reports: { id: string; moderator: string | null; signed_at: string }[];
}

/** Per module: decisions, the sample against the policy, agreement, and the signed moderation reports. */
export function CohortModerationPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/moderation' });
  const can = useCan();
  const toast = useToast();
  const [signing, setSigning] = useState<ModuleModeration | null>(null);
  const q = useQuery({
    queryKey: ['cohort-moderation', id],
    queryFn: () => call<{ data: ModuleModeration[] }>('GET', `/cohorts/${id}/moderation`).then((r) => r.data),
  });
  return (
    <>
      <Link
        to="/cohorts/$id/results"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Results
      </Link>
      <PageHeader
        title="Moderation"
        description="The sample per module against the provider's policy, and the moderator's signed reports."
      />
      {q.isPending ? (
        <Skeleton className="h-48" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.length === 0 ? (
        <Card>
          <EmptyState title="No decisions yet">Modules appear once assessors record results.</EmptyState>
        </Card>
      ) : (
        <div className="grid gap-4">
          {q.data.map((m) => (
            <Card key={m.module_id}>
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-semibold text-ink">
                  {m.code} {m.title}
                </h2>
                {m.compliant ? (
                  <Badge tone="success">Sample meets the policy</Badge>
                ) : (
                  <Badge tone="warning">Sample below {m.policy_pct}%</Badge>
                )}
              </div>
              <p className="text-small text-muted">
                {m.decisions} decisions ({m.competent} competent, {m.nyc} not yet) · {m.sampled} sampled · {m.moderated}{' '}
                moderated · {m.overturned} overturned
              </p>
              <ul className="mt-2 grid gap-1 text-small">
                {m.assessors.map((a) => (
                  <li key={a.assessor_id}>
                    {a.name}: {a.sampled} of {a.decisions} sampled{a.overturned ? `, ${a.overturned} overturned` : ''}
                  </li>
                ))}
              </ul>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                {m.reports.map((r) => (
                  <Button
                    key={r.id}
                    size="sm"
                    variant="secondary"
                    icon={<Download className="size-4" />}
                    onClick={() =>
                      void downloadFrom(`/moderation-reports/${r.id}/pdf`, `moderation-${m.code}.pdf`).catch(
                        (e: unknown) => toast(errorMessage(e), 'error'),
                      )
                    }
                  >
                    Report by {r.moderator}, {shortDate(r.signed_at)}
                  </Button>
                ))}
                {can('assessment.moderate') && m.sampled > 0 && m.moderated === m.sampled ? (
                  <Button size="sm" onClick={() => setSigning(m)}>
                    Sign a report<span className="sr-only"> for {m.code}</span>
                  </Button>
                ) : null}
              </div>
            </Card>
          ))}
        </div>
      )}
      {signing ? <ReportDialog cohortId={id} m={signing} onClose={() => setSigning(null)} /> : null}
    </>
  );
}

function ReportDialog({ cohortId, m, onClose }: { cohortId: string; m: ModuleModeration; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [findings, setFindings] = useState('');
  const [recommendations, setRecommendations] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () =>
      call('POST', `/cohorts/${cohortId}/moderation-reports`, {
        module_id: m.module_id,
        findings,
        recommendations: recommendations || null,
        confirm,
      }),
    onSuccess: () => {
      toast('Report signed');
      void qc.invalidateQueries({ queryKey: ['cohort-moderation', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Moderation report: ${m.code}`}
      description={`${m.sampled} decisions moderated, ${m.overturned} overturned.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={!confirm} onClick={() => save.mutate()}>
            Sign the report
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="grid gap-1.5">
          <label htmlFor="findings" className="text-small font-medium text-ink">
            Findings
          </label>
          <textarea
            id="findings"
            className="min-h-24 rounded-lg border border-line bg-surface px-3 py-2 text-body"
            value={findings}
            onChange={(e) => setFindings(e.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <label htmlFor="recommendations" className="text-small font-medium text-ink">
            Recommendations for assessors (optional)
          </label>
          <textarea
            id="recommendations"
            className="min-h-16 rounded-lg border border-line bg-surface px-3 py-2 text-body"
            value={recommendations}
            onChange={(e) => setRecommendations(e.target.value)}
          />
        </div>
        <label className="flex items-start gap-2 text-small">
          <input
            type="checkbox"
            className="mt-0.5 size-5"
            checked={confirm}
            onChange={(e) => setConfirm(e.target.checked)}
          />
          I confirm this report is a true account of my moderation.
        </label>
      </div>
    </Dialog>
  );
}
