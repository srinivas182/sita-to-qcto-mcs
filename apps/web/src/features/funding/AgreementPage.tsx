import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, PageHeader, Skeleton, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';
import { AgreementDialog } from './AgreementDialog';
import { BankDialog } from './BankDialog';
import { ClaimsCard } from './ClaimsCard';
import { StipendsCard } from './StipendsCard';
import { TrancheTable } from './FundingPage';
import type { AgreementDetail } from './types';

/** One grant agreement: tranches, the evidence still to upload, and each learner against each tranche. */
export function AgreementPage() {
  const { id } = useParams({ from: '/app/grant-agreements/$id' });
  const manage = useCan()('funding.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [editing, setEditing] = useState(false);
  const [bankFor, setBankFor] = useState<{ enrolmentId: string; name: string | null } | null>(null);
  const q = useQuery({
    queryKey: ['grant', id],
    queryFn: () => call<{ data: AgreementDetail }>('GET', `/grant-agreements/${id}`).then((r) => r.data),
  });
  const attach = useMutation({
    mutationFn: async ({ tranche, index, file }: { tranche: string; index: number; file: File }) => {
      const up = await uploadFile('grant_document', file);
      return call('POST', `/grant-tranches/${tranche}/evidence`, { index, file_id: up.id });
    },
    onSuccess: () => {
      toast('Evidence uploaded');
      void qc.invalidateQueries({ queryKey: ['grant', id] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const d = q.data;
  const a = d.agreement;
  return (
    <>
      <Link
        to="/cohorts/$id/funding"
        params={{ id: a.cohort_id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Funding
      </Link>
      <PageHeader
        title={`${a.funder?.name ?? 'Funder'} · ${a.reference}`}
        description={`${a.active_learners} of ${a.learners} learners · ${money(a.amount_per_learner_cents)} each (${a.funding_kind === 'co' ? 'co-funded' : 'fully funded'}) · ${shortDate(a.starts_on)} to ${shortDate(a.ends_on)}`}
        actions={
          manage ? (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              Edit agreement
            </Button>
          ) : null
        }
      />
      <Card className="mb-4">
        <CardHeader title="Tranches" />
        <TrancheTable tranches={d.tranches} />
        {manage
          ? d.tranches.flatMap((t) =>
              t.evidence.map((e, i) =>
                e.file_id ? null : (
                  <div key={`${t.id}-${i}`} className="mt-3 grid gap-1.5">
                    <label htmlFor={`ev-${t.id}-${i}`} className="text-small font-medium text-ink">
                      Tranche {t.number}: upload {e.title}
                    </label>
                    <input
                      id={`ev-${t.id}-${i}`}
                      type="file"
                      accept="application/pdf,image/jpeg,image/png"
                      onChange={(ev) => {
                        const file = ev.target.files?.[0];
                        if (file) attach.mutate({ tranche: t.id, index: i, file });
                      }}
                    />
                  </div>
                ),
              ),
            )
          : null}
      </Card>
      <ClaimsCard agreement={a} tranches={d.tranches} manage={manage} />
      <StipendsCard agreementId={a.id} manage={manage} />
      <Card>
        <CardHeader title="Learners" />
        {d.learners.length === 0 ? <p className="text-small text-muted">No learners on this agreement yet.</p> : null}
        <ul className="divide-y divide-line">
          {d.learners.map((l) => (
            <li key={l.grant_learner_id} className="grid gap-1.5 py-3">
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium text-ink">{l.name}</p>
                {l.status === 'removed' ? <Badge>Taken off</Badge> : null}
                {l.disabled ? <Badge tone="info">Disability allowance</Badge> : null}
                <span className="text-small text-muted">{l.registers} signed registers</span>
                {manage && l.status === 'active' ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setBankFor({ enrolmentId: l.enrolment_id, name: l.name })}
                  >
                    Bank details<span className="sr-only"> for {l.name}</span>
                  </Button>
                ) : null}
              </div>
              <ul className="grid gap-1 text-small">
                {d.tranches.map((t) => {
                  const r = l.tranches[t.id];
                  return (
                    <li key={t.id}>
                      <span className="font-medium text-ink">Tranche {t.number}: </span>
                      {r?.met ? (
                        <span className="inline-flex items-center gap-1 text-success">
                          <Check className="size-4" aria-hidden /> met
                        </span>
                      ) : (
                        <span className="text-muted">{r?.blockers.join(' ')}</span>
                      )}
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      </Card>
      {bankFor ? (
        <BankDialog
          enrolmentId={bankFor.enrolmentId}
          name={bankFor.name}
          agreementId={a.id}
          onClose={() => setBankFor(null)}
        />
      ) : null}
      {editing ? <AgreementDialog cohortId={a.cohort_id} detail={d} onClose={() => setEditing(false)} /> : null}
    </>
  );
}
