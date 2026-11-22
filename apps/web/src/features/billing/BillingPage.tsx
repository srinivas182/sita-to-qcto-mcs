import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { CreditCard, Download, FileText, Receipt } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  PageHeader,
  Skeleton,
  Table,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { meQuery } from '@/lib/session';
import { PayDialog } from './PayDialog';
import { PlanPicker } from './PlanPicker';
import { statusCopy, type Billing, type Invoice } from './types';

export const billingQuery = {
  queryKey: ['billing'] as const,
  queryFn: () => call<Billing>('GET', '/billing'),
};

const invoiceTone = { open: 'warning', overdue: 'danger', paid: 'success', void: 'neutral' } as const;

/** Billing: plan, next invoice, card, invoices. Everything a provider needs to stay paid up. */
export function BillingPage() {
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const search = useSearch({ strict: false }) as { payment?: string; cancelled?: string };
  const billing = useQuery(billingQuery);
  const invoices = useQuery({
    queryKey: ['billing', 'invoices'],
    queryFn: () =>
      call<{
        data: Invoice[];
        credit_notes: { id: string; number: string; total_cents: number; reason: string; issued_at: string }[];
      }>('GET', '/invoices'),
  });
  const [picking, setPicking] = useState(false);
  const [paying, setPaying] = useState<Invoice | null>(null);

  // Back from a gateway: confirm the outcome, then tidy the address bar.
  useEffect(() => {
    if (!search.payment) return;
    const reference = search.payment;
    void navigate({ to: '/billing', search: {} as never, replace: true });
    if (search.cancelled) {
      toast('Payment cancelled. Nothing was charged.', 'error');
      return;
    }
    void (async () => {
      for (let i = 0; i < 8; i++) {
        const { data } = await call<{ data: { status: string; failure_message: string | null } }>(
          'GET',
          `/payments/${reference}`,
        );
        if (data.status === 'succeeded') {
          toast('Payment received. Thank you!');
          break;
        }
        if (data.status === 'failed') {
          toast(data.failure_message ?? 'The payment did not go through.', 'error');
          break;
        }
        await new Promise((r) => setTimeout(r, 1500));
      }
      void qc.invalidateQueries({ queryKey: ['billing'] });
      void qc.invalidateQueries({ queryKey: meQuery.queryKey });
    })();
  }, [search.payment, search.cancelled, navigate, qc, toast]);

  const action = useMutation({
    mutationFn: (path: string) => call('POST', path),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['billing'] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const collection = useMutation({
    mutationFn: (value: 'auto' | 'invoice') => call('PUT', '/billing/collection', { collection: value }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['billing'] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (billing.isPending || !billing.data) return <Skeleton className="h-96" />;
  const b = billing.data;
  const s = b.subscription;

  return (
    <>
      <PageHeader
        eyebrow="Account"
        title="Billing"
        description="Your plan, invoices and how you pay. All amounts in rand; tax invoices include VAT."
        actions={
          <Button onClick={() => setPicking(true)}>
            {s && s.status !== 'cancelled' ? 'Change plan' : 'Choose a plan'}
          </Button>
        }
      />

      {b.open_invoices.length ? (
        <div className="mb-5 grid gap-2">
          {b.open_invoices.map((inv) => (
            <Alert key={inv.id} tone={inv.status === 'overdue' ? 'danger' : 'warning'}>
              <span className="flex flex-wrap items-center justify-between gap-3">
                <span>
                  Invoice <strong>{inv.number}</strong> for {money(inv.amount_due_cents)} is{' '}
                  {inv.status === 'overdue' ? 'overdue' : 'due'} on {shortDate(inv.due_at)}.
                </span>
                <Button size="sm" onClick={() => setPaying(inv)}>
                  Pay now
                </Button>
              </span>
            </Alert>
          ))}
        </div>
      ) : null}

      {!s ? (
        <Card>
          <EmptyState icon={<Receipt className="size-6" />} title="No plan yet">
            Choose a plan to start your free trial. You pay nothing until the trial ends.
          </EmptyState>
        </Card>
      ) : (
        <div className="grid gap-5 xl:grid-cols-3">
          <Card className="xl:col-span-2">
            <CardHeader
              title={
                <span className="flex items-center gap-2">
                  {s.package.name} plan <Badge tone={statusCopy[s.status].tone}>{statusCopy[s.status].label}</Badge>
                  {s.package.is_example ? <Badge tone="warning">Example pricing</Badge> : null}
                </span>
              }
              subtitle={`${money(s.interval === 'year' ? s.package.annual_fee_cents : s.package.base_fee_cents)} a ${s.interval} excl. VAT · ${s.package.included_learners} learners included, then ${money(s.package.per_learner_cents)} per active learner a month`}
            />
            <dl className="grid gap-4 sm:grid-cols-3">
              <div>
                <dt className="text-small text-muted">{s.status === 'trialing' ? 'Trial ends' : 'Current period'}</dt>
                <dd className="font-medium">
                  {s.status === 'trialing'
                    ? shortDate(s.trial_ends_at)
                    : `${shortDate(s.current_period_start)} – ${shortDate(s.current_period_end)}`}
                </dd>
              </div>
              <div>
                <dt className="text-small text-muted">Active learners</dt>
                <dd className="font-medium">{b.estimate?.active_learners ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-small text-muted">Next invoice (estimate)</dt>
                <dd className="font-medium">
                  {b.estimate ? `${money(b.estimate.total_cents)} on ${shortDate(b.estimate.date)}` : '—'}
                </dd>
              </div>
            </dl>
            {s.pending_package ? (
              <Alert tone="info">
                Changes to {s.pending_package.name} on {shortDate(s.current_period_end)}.
              </Alert>
            ) : null}
            {b.estimate ? (
              <details className="mt-4 rounded-lg border border-line px-4 py-3">
                <summary className="cursor-pointer text-small font-medium">How the next invoice is worked out</summary>
                <ul className="mt-3 grid gap-1.5 text-small">
                  {b.estimate.lines.map((l) => (
                    <li key={l.description} className="flex justify-between gap-4">
                      <span className="text-muted">
                        {l.description}
                        {l.quantity > 1 ? ` × ${l.quantity}` : ''}
                      </span>
                      <span>{money(l.amount_cents)}</span>
                    </li>
                  ))}
                  <li className="flex justify-between gap-4 text-muted">
                    <span>VAT ({b.estimate.vat_rate}%)</span>
                    <span>{money(b.estimate.vat_cents)}</span>
                  </li>
                  <li className="flex justify-between gap-4 font-semibold">
                    <span>Total</span>
                    <span>{money(b.estimate.total_cents)}</span>
                  </li>
                </ul>
              </details>
            ) : null}
            <div className="mt-4 flex flex-wrap gap-2">
              {s.status !== 'cancelled' ? (
                s.cancel_at_period_end ? (
                  <>
                    <Alert tone="warning">
                      Your plan ends on {shortDate(s.current_period_end)}. Records stay readable afterwards.
                    </Alert>
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={action.isPending}
                      onClick={() => action.mutate('/billing/resume')}
                    >
                      Keep my plan
                    </Button>
                  </>
                ) : (
                  <Button
                    variant="ghost"
                    size="sm"
                    loading={action.isPending}
                    onClick={() => action.mutate('/billing/cancel')}
                  >
                    Cancel at end of period
                  </Button>
                )
              ) : null}
            </div>
          </Card>

          <Card>
            <CardHeader title="How you pay" />
            {b.payment_method ? (
              <div className="flex items-center gap-3 rounded-lg border border-line p-3">
                <CreditCard className="size-5 text-primary-700" aria-hidden />
                <div className="flex-1">
                  <div className="font-medium capitalize">
                    {b.payment_method.brand ?? 'Card'} •••• {b.payment_method.last4 ?? ''}
                  </div>
                  <div className="text-small text-muted">
                    {b.payment_method.exp_month
                      ? `Expires ${String(b.payment_method.exp_month).padStart(2, '0')}/${b.payment_method.exp_year}`
                      : 'Saved card'}
                    {b.payment_method.expired ? ' · expired' : ''}
                  </div>
                </div>
              </div>
            ) : (
              <p className="text-small text-muted">
                No saved card. Pay an invoice by card and tick “Save this card” to pay automatically.
              </p>
            )}
            <fieldset className="mt-4 grid gap-2">
              <legend className="mb-1 text-small font-medium">When an invoice is issued</legend>
              {(
                [
                  ['auto', 'Charge my saved card automatically'],
                  ['invoice', 'I pay each invoice myself (card, Instant EFT or EFT)'],
                ] as const
              ).map(([value, label]) => (
                <label key={value} className="flex items-start gap-2 text-body">
                  <input
                    type="radio"
                    name="collection"
                    className="mt-1 size-4 accent-primary-700"
                    checked={s.collection === value}
                    disabled={value === 'auto' && !b.payment_method}
                    onChange={() => collection.mutate(value)}
                  />
                  {label}
                </label>
              ))}
            </fieldset>
            {b.profile ? (
              <div className="mt-4 border-t border-line pt-3 text-small text-muted">
                Invoices to <span className="text-ink">{b.profile.legal_name}</span>
                {b.profile.vat_number ? ` · VAT ${b.profile.vat_number}` : ''} · {b.profile.email}
              </div>
            ) : null}
          </Card>
        </div>
      )}

      <Card className="mt-5">
        <CardHeader title="Invoices" subtitle="Tax invoices and credit notes. Download any of them as PDF." />
        {invoices.isPending ? (
          <Skeleton className="h-32" />
        ) : (invoices.data?.data.length ?? 0) === 0 ? (
          <EmptyState icon={<FileText className="size-6" />} title="No invoices yet">
            Your first invoice is issued when your trial ends.
          </EmptyState>
        ) : (
          <Table
            rows={invoices.data!.data}
            rowKey={(i) => i.id}
            columns={[
              { key: 'number', header: 'Invoice', cell: (i) => <span className="font-medium">{i.number}</span> },
              { key: 'date', header: 'Date', cell: (i) => shortDate(i.issued_at) },
              { key: 'total', header: 'Total', cell: (i) => money(i.total_cents) },
              { key: 'status', header: 'Status', cell: (i) => <Badge tone={invoiceTone[i.status]}>{i.status}</Badge> },
              {
                key: 'actions',
                header: '',
                cell: (i) => (
                  <div className="flex justify-end gap-1">
                    {i.status === 'open' || i.status === 'overdue' ? (
                      <Button size="sm" onClick={() => setPaying(i)}>
                        Pay
                      </Button>
                    ) : null}
                    <a
                      href={`/v1/invoices/${i.id}/pdf`}
                      className="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-small text-muted hover:bg-line-soft hover:text-ink"
                      aria-label={`Download ${i.number} as PDF`}
                    >
                      <Download className="size-4" /> PDF
                    </a>
                  </div>
                ),
              },
            ]}
          />
        )}
        {invoices.data?.credit_notes.length ? (
          <ul className="mt-4 grid gap-1 border-t border-line pt-3 text-small">
            {invoices.data.credit_notes.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2">
                <span>
                  Credit note <strong>{c.number}</strong> · {c.reason}
                </span>
                <span className="flex items-center gap-3">
                  {money(c.total_cents)}
                  <a href={`/v1/credit-notes/${c.id}/pdf`} className="text-primary-700 underline">
                    PDF
                  </a>
                </span>
              </li>
            ))}
          </ul>
        ) : null}
      </Card>

      {picking ? <PlanPicker billing={b} onClose={() => setPicking(false)} /> : null}
      {paying ? <PayDialog invoice={paying} gateways={b.gateways} onClose={() => setPaying(null)} /> : null}
    </>
  );
}
