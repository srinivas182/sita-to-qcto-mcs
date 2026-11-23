import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { CheckCircle2, Circle, Globe, Palette, ShieldCheck, Users } from 'lucide-react';
import { Card, CardHeader, Kpi, PageHeader } from '@/components/ui';
import { FixInbox } from './FixInbox';
import { call } from '@/lib/api';
import { useCan, useMe } from '@/lib/session';
import { greeting } from '../admin/OverviewPage';

export function ProviderOverviewPage() {
  const me = useMe();
  const can = useCan();
  const manage = can('tenant.settings.manage');
  const people = useQuery({
    queryKey: ['users', 'count'],
    queryFn: () => call<{ meta: { total: number } }>('GET', '/users', undefined, { per_page: 1 }),
    enabled: can('tenant.users.view'),
  });
  const domains = useQuery({
    queryKey: ['domains', 'overview'],
    queryFn: () =>
      call<{
        data: Array<{ type: string; dns_status: string; ssl_status: string; is_primary: boolean; hostname: string }>;
      }>('GET', `/tenants/${me.tenant?.id}/domains`).then((r) => r.data),
    enabled: manage,
  });
  const ownDomain = domains.data?.some(
    (d) => d.type === 'custom' && d.dns_status === 'verified' && d.ssl_status === 'active',
  );
  const branded = !!me.tenant?.branding && Object.keys(me.tenant.branding).length > 0;

  const steps = [
    {
      done: me.security.totp || me.security.passkeys > 0,
      label: 'Secure your account with a passkey or authenticator',
      to: '/security',
      icon: ShieldCheck,
    },
    { done: branded, label: 'Add your logo and colours', to: '/brand', icon: Palette, show: manage },
    { done: !!ownDomain, label: 'Connect your own domain', to: '/brand', icon: Globe, show: manage },
    {
      done: (people.data?.meta.total ?? 0) > 1,
      label: 'Invite your centre managers and facilitators',
      to: '/users',
      icon: Users,
      show: can('tenant.users.invite'),
    },
  ].filter((s) => s.show !== false);
  const done = steps.filter((s) => s.done).length;

  return (
    <>
      <PageHeader
        eyebrow={me.tenant?.name}
        title={`Good ${greeting()}, ${me.user.first_name}`}
        description="Your provider at a glance."
      />
      <div className="grid gap-4 sm:grid-cols-3">
        <Kpi label="People" value={people.data?.meta.total ?? '—'} icon={<Users className="size-4" />} />
        <Kpi
          label="Primary domain"
          value={<span className="text-[15px]">{domains.data?.find((d) => d.is_primary)?.hostname ?? '—'}</span>}
          icon={<Globe className="size-4" />}
        />
        <Kpi
          label="Set-up"
          value={`${done}/${steps.length}`}
          icon={<CheckCircle2 className="size-4" />}
          hint="steps complete"
        />
      </div>
      <FixInbox />
      <Card className="mt-5">
        <CardHeader
          title="Get set up"
          subtitle="A few minutes now saves hours later — and keeps your QCTO submissions clean."
        />
        <div className="mb-4 h-1.5 overflow-hidden rounded-full bg-line-soft">
          <div
            className="h-full rounded-full bg-primary-600 transition-[width]"
            style={{ width: `${(done / Math.max(1, steps.length)) * 100}%` }}
          />
        </div>
        <ul className="grid gap-1">
          {steps.map((s) => (
            <li key={s.label}>
              <Link to={s.to} className="flex items-center gap-3 rounded-lg px-3 py-3 hover:bg-surface-muted">
                {s.done ? <CheckCircle2 className="size-5 text-success" /> : <Circle className="size-5 text-faint" />}
                <s.icon className="size-4 text-muted" />
                <span className={s.done ? 'text-muted line-through' : 'font-medium'}>{s.label}</span>
              </Link>
            </li>
          ))}
        </ul>
      </Card>
    </>
  );
}
