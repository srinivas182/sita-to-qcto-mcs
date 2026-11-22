import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Dialog, Field, cx, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money } from '@/lib/money';
import { meQuery } from '@/lib/session';
import type { Billing, Package, Profile } from './types';

const emptyProfile: Profile = {
  legal_name: '',
  email: '',
  address_line1: '',
  city: '',
  postal_code: '',
  vat_number: '',
};

/** Choose a package (and, the first time, the details printed on invoices). */
export function PlanPicker({ billing, onClose }: { billing: Billing; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const current = billing.subscription;
  const [interval, setInterval] = useState<'month' | 'year'>(current?.interval ?? 'month');
  const [selected, setSelected] = useState<string>(current?.package.id ?? billing.packages[0]?.id ?? '');
  const [profile, setProfile] = useState<Profile>(billing.profile ?? emptyProfile);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const subscribing = current === null || current.status === 'cancelled';
  const example = billing.packages.some((p) => p.is_example);

  const save = useMutation({
    mutationFn: () =>
      subscribing
        ? call('POST', '/billing/subscribe', { package_id: selected, interval, profile })
        : call('POST', '/billing/change', { package_id: selected, interval }),
    onSuccess: () => {
      toast(subscribing ? 'Your plan has started' : 'Plan updated');
      void qc.invalidateQueries({ queryKey: ['billing'] });
      void qc.invalidateQueries({ queryKey: meQuery.queryKey });
      onClose();
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

  const price = (p: Package) => (interval === 'year' ? p.annual_fee_cents : p.base_fee_cents);
  const pf = (name: keyof Profile, label: string, extra: Record<string, unknown> = {}) => (
    <Field
      label={label}
      value={String(profile[name] ?? '')}
      onChange={(e) => setProfile({ ...profile, [name]: e.target.value })}
      error={errors[`profile.${name}`]?.[0]}
      {...extra}
    />
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={subscribing ? 'Choose your plan' : 'Change plan'}
      description="Prices exclude VAT. Upgrades apply now; downgrades apply at the end of the current period."
    >
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {example ? (
          <Alert tone="warning">Example pricing — can be changed. Final prices are set by the platform owner.</Alert>
        ) : null}
        <div
          role="radiogroup"
          aria-label="Billing interval"
          className="inline-flex w-fit rounded-lg border border-line p-1"
        >
          {(['month', 'year'] as const).map((i) => (
            <button
              key={i}
              type="button"
              role="radio"
              aria-checked={interval === i}
              onClick={() => setInterval(i)}
              className={cx(
                'rounded-md px-3 py-1.5 text-small font-medium',
                interval === i ? 'bg-primary-700 text-white' : 'text-muted hover:text-ink',
              )}
            >
              {i === 'month' ? 'Monthly' : 'Yearly'}
            </button>
          ))}
        </div>
        <div role="radiogroup" aria-label="Package" className="grid gap-2">
          {billing.packages.map((p) => {
            const on = selected === p.id;
            return (
              <button
                key={p.id}
                type="button"
                role="radio"
                aria-checked={on}
                onClick={() => setSelected(p.id)}
                className={cx(
                  'grid gap-1 rounded-xl border p-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100',
                  on ? 'border-primary-500 bg-primary-50/50' : 'border-line hover:border-primary-200',
                )}
              >
                <span className="flex items-center justify-between gap-2">
                  <span className="flex items-center gap-2 font-semibold text-ink">
                    {p.name}
                    {current?.package.id === p.id ? <Badge tone="primary">Current</Badge> : null}
                  </span>
                  <span className="text-right">
                    <span className="text-[15px] font-semibold text-ink">{money(price(p))}</span>
                    <span className="text-small text-muted"> /{interval === 'year' ? 'year' : 'month'}</span>
                  </span>
                </span>
                <span className="text-small text-muted">
                  {p.included_learners} learners included, then {money(p.per_learner_cents)} per active learner a month
                  {interval === 'year' && p.annual_discount_percent
                    ? ` · ${p.annual_discount_percent}% off yearly`
                    : ''}
                </span>
                {on && p.features.length ? (
                  <ul className="mt-1 grid gap-1 text-small text-ink sm:grid-cols-2">
                    {p.features.map((f) => (
                      <li key={f} className="flex items-center gap-1.5">
                        <Check className="size-3.5 text-success" aria-hidden /> {f}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </button>
            );
          })}
        </div>
        {subscribing ? (
          <fieldset className="grid gap-3 sm:grid-cols-2">
            <legend className="mb-1 text-small font-semibold text-ink">
              Invoice details (printed on your tax invoices)
            </legend>
            {pf('legal_name', 'Registered name', { required: true, className: 'sm:col-span-2' })}
            {pf('vat_number', 'VAT number (optional)', { inputMode: 'numeric' })}
            {pf('email', 'Accounts email', { type: 'email', required: true })}
            {pf('address_line1', 'Street address', { required: true, className: 'sm:col-span-2' })}
            {pf('city', 'City', { required: true })}
            {pf('postal_code', 'Postal code', { inputMode: 'numeric' })}
          </fieldset>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!selected}>
            {subscribing
              ? `Start ${billing.packages.find((p) => p.id === selected)?.trial_days ?? 14}-day trial`
              : 'Change plan'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
