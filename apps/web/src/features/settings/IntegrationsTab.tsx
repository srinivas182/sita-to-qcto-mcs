import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, CheckCircle2, Plug, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, Card, Skeleton, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { IntegrationDialog } from './IntegrationDialog';
import { capabilityLabels, type Driver, type IntegrationType } from './types';

const emptyText: Record<string, string> = {
  payment: 'No payment gateway is switched on yet, so providers cannot pay online.',
  sms: 'No SMS provider is switched on yet.',
  whatsapp: 'WhatsApp is not switched on yet; sign-in codes go by SMS.',
  email: 'No email service is switched on yet; the server default is used.',
  storage: 'No storage is switched on, so files are kept on the application server.',
};

export const integrationsKey = ['admin', 'settings', 'integrations'] as const;

export function useIntegrations() {
  return useQuery({
    queryKey: integrationsKey,
    queryFn: () => call<{ data: IntegrationType[] }>('GET', '/admin/settings/integrations').then((r) => r.data),
  });
}

/** One integration type: every available driver, what is set up, on and in which order. */
export function IntegrationsTab({ type }: { type: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const query = useIntegrations();
  const [editing, setEditing] = useState<Driver | null>(null);
  const group = query.data?.find((t) => t.type === type);

  const reorder = useMutation({
    mutationFn: (ids: string[]) => call('PUT', '/admin/settings/integrations/order', { type, ids }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: integrationsKey }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (query.isPending || !group) return <Skeleton className="h-80" />;

  const configured = group.drivers.filter((d) => d.integration);
  const enabled = configured.filter((d) => d.integration!.enabled);
  const ordered = !group.single && enabled.length > 1;

  const move = (driver: Driver, delta: number) => {
    const ids = configured.map((d) => d.integration!.id);
    const from = ids.indexOf(driver.integration!.id);
    const to = from + delta;
    if (to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to]!, ids[from]!];
    reorder.mutate(ids);
  };

  return (
    <>
      {enabled.length === 0 ? (
        <div className="mb-4 rounded-lg border border-warning/30 bg-warning-bg px-4 py-3 text-small text-warning">
          {emptyText[group.type]}
        </div>
      ) : null}
      <ul className="grid gap-3">
        {group.drivers.map((driver) => {
          const i = driver.integration;
          const position = i ? configured.findIndex((d) => d.driver === driver.driver) : -1;
          return (
            <li key={driver.driver}>
              <Card className="flex flex-wrap items-center gap-4 p-4 sm:flex-nowrap">
                <span
                  className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-[15px] font-bold text-primary-700"
                  aria-hidden
                >
                  {driver.label.charAt(0)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-card-title font-semibold text-ink">{driver.label}</h3>
                    {i ? (
                      i.enabled ? (
                        <Badge tone="success" dot>
                          On
                        </Badge>
                      ) : (
                        <Badge>Off</Badge>
                      )
                    ) : (
                      <Badge>Not set up</Badge>
                    )}
                    {i && group.modes ? (
                      <Badge tone={i.mode === 'live' ? 'primary' : 'warning'}>
                        {i.mode === 'live' ? 'Live' : 'Sandbox'}
                      </Badge>
                    ) : null}
                    {ordered && i?.enabled ? <Badge tone="info">Order {position + 1}</Badge> : null}
                  </div>
                  <p className="mt-0.5 text-small text-muted">{driver.description}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-muted">
                    {driver.capabilities.map((c) => (
                      <span key={c}>{capabilityLabels[c] ?? c}</span>
                    ))}
                    {i?.last_test ? (
                      <span
                        className={
                          i.last_test.ok
                            ? 'flex items-center gap-1 text-success'
                            : 'flex items-center gap-1 text-danger'
                        }
                      >
                        {i.last_test.ok ? (
                          <CheckCircle2 className="size-3.5" aria-hidden />
                        ) : (
                          <XCircle className="size-3.5" aria-hidden />
                        )}
                        {i.last_test.ok ? 'Test passed' : 'Test failed'} · {new Date(i.last_test.at).toLocaleString()}
                      </span>
                    ) : null}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  {ordered && i ? (
                    <>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Move ${driver.label} up`}
                        disabled={position === 0 || reorder.isPending}
                        onClick={() => move(driver, -1)}
                        icon={<ArrowUp className="size-4" />}
                      />
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Move ${driver.label} down`}
                        disabled={position === configured.length - 1 || reorder.isPending}
                        onClick={() => move(driver, 1)}
                        icon={<ArrowDown className="size-4" />}
                      />
                    </>
                  ) : null}
                  <Button
                    size="sm"
                    variant={i ? 'secondary' : 'primary'}
                    icon={i ? undefined : <Plug className="size-4" />}
                    onClick={() => setEditing(driver)}
                  >
                    {i ? 'Manage' : 'Set up'}
                  </Button>
                </div>
              </Card>
            </li>
          );
        })}
      </ul>
      {editing ? (
        <IntegrationDialog
          group={group}
          driver={group.drivers.find((d) => d.driver === editing.driver) ?? editing}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </>
  );
}
