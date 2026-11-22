import { useMutation } from '@tanstack/react-query';
import { Building2, CreditCard, Landmark } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Alert, Button, Dialog, cx } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { money } from '@/lib/money';
import type { Gateway, Invoice } from './types';

interface Checkout {
  url: string;
  method: 'GET' | 'POST';
  fields: Record<string, string>;
}

/** Send the browser to the gateway: a link, or a signed form post (PayFast). */
function go(checkout: Checkout) {
  if (checkout.method === 'GET') {
    window.location.assign(checkout.url);
    return;
  }
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = checkout.url;
  for (const [name, value] of Object.entries(checkout.fields)) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
}

/** Pay an invoice: card, Instant EFT or a bank EFT with the invoice reference. */
export function PayDialog({
  invoice,
  gateways,
  onClose,
}: {
  invoice: Invoice;
  gateways: Gateway[];
  onClose: () => void;
}) {
  const options = [
    ...gateways
      .filter((g) => g.capabilities.includes('card'))
      .map((g) => ({ key: `${g.driver}|card`, gateway: g, method: 'card' as const })),
    ...gateways
      .filter((g) => g.capabilities.includes('instant_eft'))
      .map((g) => ({ key: `${g.driver}|instant_eft`, gateway: g, method: 'instant_eft' as const })),
  ];
  const [choice, setChoice] = useState(options[0]?.key ?? 'eft');
  const [save, setSave] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const selected = options.find((o) => o.key === choice);

  const pay = useMutation({
    mutationFn: () =>
      call<{ checkout: Checkout }>('POST', `/invoices/${invoice.id}/pay`, {
        driver: selected!.gateway.driver,
        method: selected!.method,
        save_card: selected!.method === 'card' && save,
        return_url: `${window.location.origin}/billing`,
      }),
    onSuccess: (r) => go(r.checkout),
    onError: (e) => setError(errorMessage(e)),
  });

  const row = (key: string, icon: ReactNode, title: string, note: string) => (
    <label
      key={key}
      className={cx(
        'flex cursor-pointer items-center gap-3 rounded-xl border p-3',
        choice === key ? 'border-primary-500 bg-primary-50/50' : 'border-line hover:border-primary-200',
      )}
    >
      <input
        type="radio"
        name="pay"
        value={key}
        checked={choice === key}
        onChange={() => setChoice(key)}
        className="sr-only"
      />
      <span className="grid size-9 place-items-center rounded-lg bg-primary-50 text-primary-700">{icon}</span>
      <span className="min-w-0 flex-1">
        <span className="block font-medium text-ink">{title}</span>
        <span className="block text-small text-muted">{note}</span>
      </span>
    </label>
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Pay ${invoice.number}`}
      description={`${money(invoice.amount_due_cents)} due, incl. VAT`}
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div role="radiogroup" aria-label="Payment method" className="grid gap-2">
          {options.map((o) =>
            row(
              o.key,
              o.method === 'card' ? <CreditCard className="size-4" /> : <Landmark className="size-4" />,
              `${o.method === 'card' ? 'Card' : 'Instant EFT'} · ${o.gateway.label}`,
              o.gateway.mode === 'sandbox'
                ? 'Sandbox — no real money moves'
                : o.method === 'card'
                  ? 'Visa, Mastercard and more'
                  : 'Pay straight from your bank',
            ),
          )}
          {row(
            'eft',
            <Building2 className="size-4" />,
            'Bank EFT',
            `Use reference ${invoice.eft_reference}. We mark it paid when it arrives.`,
          )}
        </div>
        {selected?.method === 'card' ? (
          <label className="flex items-center gap-2 text-body">
            <input
              type="checkbox"
              className="size-4 accent-primary-700"
              checked={save}
              onChange={(e) => setSave(e.target.checked)}
            />
            Save this card and pay future invoices automatically
          </label>
        ) : null}
        {choice === 'eft' ? (
          <Alert tone="info">
            Pay {money(invoice.amount_due_cents)} to the bank account on the invoice with reference{' '}
            <strong>{invoice.eft_reference}</strong>.
          </Alert>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            {choice === 'eft' ? 'Done' : 'Cancel'}
          </Button>
          {choice !== 'eft' ? (
            <Button loading={pay.isPending} onClick={() => pay.mutate()}>
              Continue to payment
            </Button>
          ) : null}
        </div>
      </div>
    </Dialog>
  );
}
