import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { AlertTriangle, CircleAlert, Info } from 'lucide-react';
import { Card, CardHeader, Kpi } from '@/components/ui';
import { call } from '@/lib/api';
import { money } from '@/lib/money';

interface Item {
  severity: 'danger' | 'warning' | 'info';
  kind: string;
  title: string;
  detail: string;
  link: { to: string; params: Record<string, string> };
}

interface Inbox {
  items: Item[];
  funding: {
    claimable_cents: number;
    claimed_open_cents: number;
    paid_this_year_cents: number;
    stipends_held: number;
  } | null;
}

const icon = {
  danger: <CircleAlert className="size-5 text-danger" aria-hidden />,
  warning: <AlertTriangle className="size-5 text-warning" aria-hidden />,
  info: <Info className="size-5 text-info" aria-hidden />,
};

/** What is stuck across the provider, most urgent first, each linking to where it is fixed. */
export function FixInbox() {
  const q = useQuery({
    queryKey: ['fix-inbox'],
    queryFn: () => call<{ data: Inbox }>('GET', '/fix-inbox').then((r) => r.data),
  });
  if (!q.data) return null;
  const f = q.data.funding;
  return (
    <>
      {f ? (
        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Kpi label="Ready to claim" value={money(f.claimable_cents)} hint="tranches with evidence in" />
          <Kpi label="Claimed, not yet paid" value={money(f.claimed_open_cents)} />
          <Kpi label="Claims paid this year" value={money(f.paid_this_year_cents)} />
          <Kpi label="Stipends on hold" value={f.stipends_held} hint="this month and last" />
        </div>
      ) : null}
      <Card className="mt-5">
        <CardHeader
          title="To fix"
          subtitle="Rejections, funder queries, money waiting and dates closing. Each item opens where it is fixed."
        />
        {q.data.items.length === 0 ? (
          <p className="text-small text-muted">Nothing waiting. Well done.</p>
        ) : (
          <ul className="divide-y divide-line">
            {q.data.items.map((i, n) => (
              <li key={n}>
                <Link
                  to={i.link.to}
                  params={i.link.params}
                  className="flex items-start gap-3 rounded-lg px-2 py-3 hover:bg-surface-muted"
                >
                  {icon[i.severity]}
                  <span className="grid gap-0.5">
                    <span className="font-medium text-ink">{i.title}</span>
                    <span className="text-small text-muted">{i.detail}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  );
}
