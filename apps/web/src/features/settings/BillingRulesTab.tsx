import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Alert, Button, Card, CardHeader, Field, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';

interface Rules {
  trial_days: number;
  invoice_due_days: number;
  retry_days: number[];
  read_only_after_days: number;
  suspend_after_days: number;
  trial_reminder_days: number;
}

export function BillingRulesTab() {
  const query = useQuery({
    queryKey: ['admin', 'settings', 'billing-rules'],
    queryFn: () => call<{ data: Rules }>('GET', '/admin/settings/billing-rules').then((r) => r.data),
  });
  if (query.isPending || !query.data) return <Skeleton className="h-72" />;
  return <RulesForm initial={query.data} />;
}

function RulesForm({ initial }: { initial: Rules }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState({ ...initial, retry: initial.retry_days.join(', ') });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () =>
      call<{ data: Rules }>('PUT', '/admin/settings/billing-rules', {
        trial_days: Number(f.trial_days),
        invoice_due_days: Number(f.invoice_due_days),
        retry_days: f.retry
          .split(/[,\s]+/)
          .filter(Boolean)
          .map(Number),
        read_only_after_days: Number(f.read_only_after_days),
        suspend_after_days: Number(f.suspend_after_days),
        trial_reminder_days: Number(f.trial_reminder_days),
      }),
    onSuccess: (r) => {
      toast('Billing rules saved');
      qc.setQueryData(['admin', 'settings', 'billing-rules'], r.data);
    },
    onError: (e) => {
      setError(errorMessage(e));
      if (e instanceof ApiError) setErrors(e.fields);
    },
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrors({});
    save.mutate();
  };
  const num = (k: keyof Rules, label: string, hint?: string) => (
    <Field
      label={label}
      type="number"
      min={0}
      value={String(f[k])}
      onChange={(e) => setF({ ...f, [k]: e.target.value })}
      error={errors[k]?.[0]}
      hint={hint}
    />
  );

  return (
    <Card>
      <CardHeader
        title="Billing rules"
        subtitle="How invoices are collected. Changes apply from the next billing run."
      />
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          {num('trial_days', 'Default trial (days)', 'Each package can set its own.')}
          {num('trial_reminder_days', 'Reminder before a trial ends (days)')}
          {num('invoice_due_days', 'Invoice payable within (days)')}
          <Field
            label="Card retries (days after the due date)"
            value={f.retry}
            onChange={(e) => setF({ ...f, retry: e.target.value })}
            error={errors.retry_days?.[0]}
            hint="e.g. 1, 3, 7"
          />
          {num('read_only_after_days', 'Read-only after (days overdue)', 'Everything stays visible; changes pause.')}
          {num('suspend_after_days', 'Suspended after (days overdue)', 'Only billing works. Nothing is deleted.')}
        </div>
        <div>
          <Button type="submit" loading={save.isPending}>
            Save rules
          </Button>
        </div>
      </form>
    </Card>
  );
}
