import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { CircleAlert, Clock } from 'lucide-react';
import type { Me } from '@/lib/api';

const copy: Record<string, { tone: string; text: string }> = {
  past_due: { tone: 'bg-warning-bg text-warning', text: 'An invoice is overdue. Please pay to avoid interruptions.' },
  read_only: {
    tone: 'bg-danger-bg text-danger',
    text: 'Your account is read-only until the overdue invoice is paid. Everything is still visible.',
  },
  suspended: {
    tone: 'bg-danger-bg text-danger',
    text: 'Your account is suspended until the overdue invoice is paid. Your records are safe.',
  },
  cancelled: {
    tone: 'bg-line-soft text-muted',
    text: 'Your plan has ended. Records are read-only. Choose a plan to continue.',
  },
};

/** A calm, persistent line about the plan when it needs attention, or when a trial is ending. */
export function BillingBanner({ me, canManage }: { me: Me; canManage: boolean }) {
  // Read the clock once per mount (render must stay pure).
  const [now] = useState(() => Date.now());
  const billing = me.tenant?.billing;
  if (!billing) return null;

  if (billing.status === 'trialing' && billing.trial_ends_at) {
    const days = Math.ceil((new Date(billing.trial_ends_at).getTime() - now) / 86_400_000);
    if (days > 7) return null;
    return (
      <div
        className="mx-4 mt-4 flex items-center gap-3 rounded-lg bg-info-bg px-4 py-3 text-small text-info sm:mx-6"
        role="status"
      >
        <Clock className="size-4 flex-none" aria-hidden />
        <span className="flex-1">
          Your trial ends in {Math.max(0, days)} day{days === 1 ? '' : 's'}.
        </span>
        {canManage ? (
          <Link to="/billing" className="font-semibold underline">
            Billing
          </Link>
        ) : null}
      </div>
    );
  }

  const c = copy[billing.status];
  if (!c) return null;

  return (
    <div
      className={`mx-4 mt-4 flex items-center gap-3 rounded-lg px-4 py-3 text-small sm:mx-6 ${c.tone}`}
      role="status"
    >
      <CircleAlert className="size-4 flex-none" aria-hidden />
      <span className="flex-1">{c.text}</span>
      {canManage ? (
        <Link to="/billing" className="font-semibold underline">
          Pay now
        </Link>
      ) : null}
    </div>
  );
}
