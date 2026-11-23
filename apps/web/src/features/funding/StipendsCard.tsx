import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Dialog, Field } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money } from '@/lib/money';
import { type StipendRule, type StipendRun, monthName } from './stipends';

const fieldError = (e: unknown) =>
  e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e);

/** The stipend rule on an agreement and its monthly runs. */
export function StipendsCard({ agreementId, manage }: { agreementId: string; manage: boolean }) {
  const qc = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [month, setMonth] = useState(() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);
    return d.toISOString().slice(0, 7);
  });
  const [error, setError] = useState<string | null>(null);
  const q = useQuery({
    queryKey: ['stipend-runs', agreementId],
    queryFn: () =>
      call<{ data: StipendRun[]; rule: StipendRule }>('GET', `/grant-agreements/${agreementId}/stipend-runs`),
  });
  const propose = useMutation({
    mutationFn: () => call('POST', `/grant-agreements/${agreementId}/stipend-runs`, { month }),
    onSuccess: () => {
      setError(null);
      void qc.invalidateQueries({ queryKey: ['stipend-runs', agreementId] });
    },
    onError: (e) => setError(fieldError(e)),
  });
  const rule = q.data?.rule;
  return (
    <Card className="mb-4">
      <CardHeader
        title="Stipends"
        action={
          manage ? (
            <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
              Stipend rule
            </Button>
          ) : null
        }
      />
      {rule ? (
        <p className="mb-3 text-small text-muted">
          {rule.stipend_cents == null
            ? 'No stipend set for this agreement.'
            : `${money(rule.stipend_cents)} a month · full at ${rule.full_pct}% attendance or more · ${rule.prorata ? 'pro-rata below that' : 'full above the hold level'} · held below ${rule.hold_pct}%. Paid only on the signed monthly register.`}
        </p>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {manage && rule?.stipend_cents != null ? (
        <div className="mb-3 flex flex-wrap items-end gap-2">
          <Field label="Month" type="month" value={month} onChange={(e) => setMonth(e.target.value)} />
          <Button loading={propose.isPending} onClick={() => propose.mutate()}>
            Propose stipends
          </Button>
        </div>
      ) : null}
      {q.data?.data.length ? (
        <ul className="divide-y divide-line text-small">
          {q.data.data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <Link
                to="/stipend-runs/$id"
                params={{ id: r.id }}
                className="font-medium text-primary-700 hover:underline"
              >
                {monthName(r.month)}
              </Link>
              <span className="text-ink">{money(r.total_cents)}</span>
              <Badge tone={r.status === 'paid' ? 'success' : r.status === 'confirmed' ? 'info' : 'warning'}>
                {r.status === 'draft' ? 'To check' : r.status === 'confirmed' ? 'Confirmed' : 'Paid'}
              </Badge>
            </li>
          ))}
        </ul>
      ) : null}
      {editing && rule ? <RuleDialog agreementId={agreementId} rule={rule} onClose={() => setEditing(false)} /> : null}
    </Card>
  );
}

function RuleDialog({ agreementId, rule, onClose }: { agreementId: string; rule: StipendRule; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    stipend: rule.stipend_cents == null ? '' : String(rule.stipend_cents / 100),
    full_pct: String(rule.full_pct),
    hold_pct: String(rule.hold_pct),
    prorata: rule.prorata,
  });
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () =>
      call('PUT', `/grant-agreements/${agreementId}/stipend-rule`, {
        ...form,
        stipend: form.stipend === '' ? null : form.stipend,
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['stipend-runs', agreementId] });
      onClose();
    },
    onError: (e) => setError(fieldError(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Stipend rule"
      description="Each month the platform proposes stipends from the signed register using this rule. You check and confirm every month."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label="Monthly stipend (R)"
          type="number"
          min={0}
          step="0.01"
          value={form.stipend}
          onChange={(e) => setForm({ ...form, stipend: e.target.value })}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Full stipend at attendance of (%)"
            type="number"
            min={1}
            max={100}
            value={form.full_pct}
            onChange={(e) => setForm({ ...form, full_pct: e.target.value })}
          />
          <Field
            label="Hold below attendance of (%)"
            type="number"
            min={0}
            max={100}
            value={form.hold_pct}
            onChange={(e) => setForm({ ...form, hold_pct: e.target.value })}
          />
        </div>
        <label className="flex items-center gap-2 text-small">
          <input
            type="checkbox"
            className="size-5"
            checked={form.prorata}
            onChange={(e) => setForm({ ...form, prorata: e.target.checked })}
          />
          Pay pro-rata between the two levels
        </label>
      </div>
    </Dialog>
  );
}
