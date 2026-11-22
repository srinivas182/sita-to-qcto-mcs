import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Alert, Skeleton, cx } from '@/components/ui';
import { call } from '@/lib/api';
import { money } from '@/lib/money';
import type { Package } from '@/features/billing/types';
import { ApplyLayout } from './ApplyLayout';

/** Public pricing: what each plan costs and includes, VAT shown separately. */
export function PricingPage() {
  const [interval, setInterval] = useState<'month' | 'year'>('month');
  const q = useQuery({
    queryKey: ['packages'],
    queryFn: () => call<{ data: Package[]; example_pricing: boolean }>('GET', '/packages'),
  });

  return (
    <ApplyLayout wide>
      <div className="text-center">
        <h1 className="text-[32px] font-semibold tracking-tight">Simple pricing for training providers</h1>
        <p className="mx-auto mt-2 max-w-2xl text-body text-muted">
          QCTO-ready learner records, assessments and reporting. Every plan starts with a free trial. Prices exclude 15%
          VAT.
        </p>
        <div className="mt-6 inline-flex gap-1 rounded-lg bg-line-soft p-1" role="group" aria-label="Billing period">
          {(['month', 'year'] as const).map((i) => (
            <button
              key={i}
              type="button"
              aria-pressed={interval === i}
              onClick={() => setInterval(i)}
              className={cx(
                'rounded-md px-3 py-1 text-small',
                interval === i ? 'bg-surface text-ink shadow-sm' : 'text-muted',
              )}
            >
              {i === 'month' ? 'Monthly' : 'Yearly (save 15%)'}
            </button>
          ))}
        </div>
      </div>
      {q.data?.example_pricing ? (
        <div className="mx-auto mt-6 max-w-xl">
          <Alert tone="warning">Example pricing — can be changed. Final prices are confirmed before go-live.</Alert>
        </div>
      ) : null}
      <div className="mt-8 grid gap-5 md:grid-cols-3">
        {q.isPending
          ? [0, 1, 2].map((i) => <Skeleton key={i} className="h-80" />)
          : q.data?.data.map((p, i) => (
              <section key={p.id} className={cx('card flex flex-col p-6', i === 1 && 'ring-2 ring-primary-500')}>
                <h2 className="text-card-title font-semibold">{p.name}</h2>
                {p.description ? <p className="mt-1 text-small text-muted">{p.description}</p> : null}
                <p className="mt-4">
                  <span className="text-[28px] font-semibold tabular-nums">
                    {money(interval === 'year' ? p.annual_fee_cents : p.base_fee_cents)}
                  </span>
                  <span className="text-small text-muted"> / {interval === 'year' ? 'year' : 'month'}</span>
                </p>
                <p className="text-small text-muted">
                  {p.included_learners} active learners included, then {money(p.per_learner_cents)} each per month
                </p>
                <ul className="mt-5 grid gap-2 text-small">
                  {p.features.map((f) => (
                    <li key={f} className="flex gap-2">
                      <Check className="size-4 shrink-0 text-success" aria-hidden /> {f}
                    </li>
                  ))}
                </ul>
                <Link
                  to="/apply"
                  className="mt-auto inline-flex h-10 items-center justify-center rounded-lg bg-primary-700 px-4 pt-0 font-medium text-white hover:bg-primary-800 [margin-top:auto]"
                >
                  Start {p.trial_days}-day trial
                </Link>
              </section>
            ))}
      </div>
    </ApplyLayout>
  );
}
