import { useNavigate, useSearch } from '@tanstack/react-router';
import {
  Building2,
  CreditCard,
  Gavel,
  HardDrive,
  Mail,
  MessageCircle,
  MessageSquareText,
  type LucideIcon,
} from 'lucide-react';
import { useRef, type KeyboardEvent } from 'react';
import { PageHeader, cx } from '@/components/ui';
import { BillingRulesTab } from './BillingRulesTab';
import { CompanyTab } from './CompanyTab';
import { IntegrationsTab } from './IntegrationsTab';

const tabs: { key: string; label: string; icon: LucideIcon; description: string }[] = [
  { key: 'company', label: 'Company', icon: Building2, description: 'Printed on every tax invoice.' },
  {
    key: 'billing',
    label: 'Billing rules',
    icon: Gavel,
    description: 'Trials, due dates, card retries and when overdue accounts are limited.',
  },
  {
    key: 'payment',
    label: 'Payments',
    icon: CreditCard,
    description: 'Gateways training providers use to pay their subscriptions and invoices.',
  },
  {
    key: 'sms',
    label: 'SMS',
    icon: MessageSquareText,
    description: 'Used when WhatsApp cannot deliver, in the order you set.',
  },
  { key: 'whatsapp', label: 'WhatsApp', icon: MessageCircle, description: 'Sign-in codes and notifications.' },
  { key: 'email', label: 'Email', icon: Mail, description: 'Invoices, invitations and reminders.' },
  {
    key: 'storage',
    label: 'Storage',
    icon: HardDrive,
    description: 'Where uploaded files are kept. One is on at a time.',
  },
];

/** Settings (Super Admin): company details and every external service, in one place. */
export function SettingsPage() {
  const search = useSearch({ strict: false }) as { tab?: string };
  const navigate = useNavigate();
  const active = tabs.find((t) => t.key === search.tab) ?? tabs[0]!;
  const refs = useRef<(HTMLButtonElement | null)[]>([]);

  const select = (key: string) => void navigate({ to: '/settings', search: { tab: key } as never, replace: true });

  // Arrow keys move between tabs (WAI-ARIA tabs pattern).
  const onKey = (e: KeyboardEvent, index: number) => {
    const delta =
      e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
    if (!delta) return;
    e.preventDefault();
    const next = (index + delta + tabs.length) % tabs.length;
    refs.current[next]?.focus();
    select(tabs[next]!.key);
  };

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Settings"
        description="Company details and the services the platform uses. Keys are stored encrypted and never shown again."
      />
      <div className="grid gap-5 lg:grid-cols-[220px_1fr]">
        <div
          role="tablist"
          aria-label="Settings"
          aria-orientation="vertical"
          className="flex gap-1 overflow-x-auto lg:flex-col"
        >
          {tabs.map((t, i) => {
            const selected = t.key === active.key;
            return (
              <button
                key={t.key}
                ref={(el) => {
                  refs.current[i] = el;
                }}
                role="tab"
                id={`tab-${t.key}`}
                aria-selected={selected}
                aria-controls={`panel-${t.key}`}
                tabIndex={selected ? 0 : -1}
                onClick={() => select(t.key)}
                onKeyDown={(e) => onKey(e, i)}
                className={cx(
                  'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-left text-body font-medium transition-colors focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary-100',
                  selected ? 'bg-primary-50 text-primary-700' : 'text-muted hover:bg-line-soft hover:text-ink',
                )}
              >
                <t.icon className="size-4" aria-hidden />
                {t.label}
              </button>
            );
          })}
        </div>
        <section role="tabpanel" id={`panel-${active.key}`} aria-labelledby={`tab-${active.key}`} className="min-w-0">
          <p className="mb-4 text-small text-muted">{active.description}</p>
          {active.key === 'company' ? (
            <CompanyTab />
          ) : active.key === 'billing' ? (
            <BillingRulesTab />
          ) : (
            <IntegrationsTab type={active.key} />
          )}
        </section>
      </div>
    </>
  );
}
