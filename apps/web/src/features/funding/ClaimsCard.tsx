import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Dialog } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { type Claim, claimStatus } from './claims';
import type { Agreement, Tranche } from './types';

/** Claims made on an agreement, and a Claim button per tranche that is ready. */
export function ClaimsCard({
  agreement,
  tranches,
  manage,
}: {
  agreement: Agreement;
  tranches: Tranche[];
  manage: boolean;
}) {
  const [claiming, setClaiming] = useState<Tranche | null>(null);
  const q = useQuery({
    queryKey: ['claims', agreement.id],
    queryFn: () => call<{ data: Claim[] }>('GET', `/grant-agreements/${agreement.id}/claims`).then((r) => r.data),
  });
  const number = (id: string) => tranches.find((t) => t.id === id)?.number;
  return (
    <Card className="mb-4">
      <CardHeader title="Claims" />
      {manage ? (
        <div className="mb-3 flex flex-wrap gap-2">
          {tranches.map((t) => (
            <Button
              key={t.id}
              size="sm"
              variant={t.ready ? 'primary' : 'secondary'}
              disabled={!t.ready}
              onClick={() => setClaiming(t)}
            >
              Claim tranche {t.number}
            </Button>
          ))}
        </div>
      ) : null}
      {q.data?.length === 0 ? (
        <p className="text-small text-muted">
          No claims yet. A tranche can be claimed once a learner meets it and its evidence is uploaded.
        </p>
      ) : null}
      <ul className="divide-y divide-line text-small">
        {q.data?.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <Link to="/grant-claims/$id" params={{ id: c.id }} className="font-medium text-primary-700 hover:underline">
              {c.number}
            </Link>
            <span className="text-muted">
              Tranche {number(c.tranche_id)} · {c.learners.length} learners
              {c.sent_on ? ` · sent ${shortDate(c.sent_on)}` : ''}
            </span>
            <span className="text-ink">{money(c.amount_cents)}</span>
            <Badge tone={claimStatus[c.status].tone}>{claimStatus[c.status].label}</Badge>
          </li>
        ))}
      </ul>
      {claiming ? <ClaimDialog agreement={agreement} tranche={claiming} onClose={() => setClaiming(null)} /> : null}
    </Card>
  );
}

function ClaimDialog({ agreement, tranche, onClose }: { agreement: Agreement; tranche: Tranche; onClose: () => void }) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [disability, setDisability] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const make = useMutation({
    mutationFn: () =>
      call<{ data: Claim }>('POST', `/grant-tranches/${tranche.id}/claims`, { include_disability: disability }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['claims', agreement.id] });
      void navigate({ to: '/grant-claims/$id', params: { id: r.data.id } });
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Claim tranche ${tranche.number}`}
      description={`Learners who met this tranche and aren't on an earlier claim are added at ${money(tranche.per_learner_cents)} each. The claim gets its invoice number now.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={make.isPending} onClick={() => make.mutate()}>
            Make the claim
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <p className="text-small text-ink">
          {tranche.learners_met} of {tranche.learners_total} learners have met it.
        </p>
        {agreement.disability_allowance_cents != null ? (
          <label className="flex items-center gap-2 text-small">
            <input
              type="checkbox"
              className="size-5"
              checked={disability}
              onChange={(e) => setDisability(e.target.checked)}
            />
            Add the disability allowance ({money(agreement.disability_allowance_cents)}) for learners not claimed for it
            yet
          </label>
        ) : null}
      </div>
    </Dialog>
  );
}
