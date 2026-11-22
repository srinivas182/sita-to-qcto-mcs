import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, CheckCircle2, Mail, PauseCircle, PlayCircle, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Alert,
  Button,
  Card,
  CardHeader,
  Dialog,
  Field,
  PageHeader,
  Skeleton,
  StatusBadge,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { DomainsPanel } from '../provider/DomainsPanel';
import type { TenantRow } from './types';

export function TenantDetailPage() {
  const { id } = useParams({ strict: false }) as { id: string };
  const can = useCan();
  const qc = useQueryClient();
  const toast = useToast();
  const tenant = useQuery({
    queryKey: ['admin', 'tenant', id],
    queryFn: () => call<{ data: TenantRow }>('GET', `/admin/tenants/${id}`).then((r) => r.data),
  });
  const [suspending, setSuspending] = useState(false);

  const act = useMutation({
    mutationFn: ({ verb, body }: { verb: string; body?: unknown }) =>
      call('POST', `/admin/tenants/${id}/${verb}`, body),
    onSuccess: (_d, v) => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      toast(
        {
          approve: 'Provider approved — it is live',
          verify: 'Marked as verified',
          suspend: 'Provider suspended',
          reactivate: 'Provider reactivated',
        }[v.verb] ?? 'Done',
      );
      setSuspending(false);
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (tenant.isPending)
    return (
      <div className="grid gap-4">
        <Skeleton className="h-16 w-1/2" />
        <Skeleton className="h-64" />
      </div>
    );
  if (!tenant.data) return <Alert tone="danger">Provider not found.</Alert>;
  const t = tenant.data;
  const manage = can('platform.tenants.manage');

  return (
    <>
      <Link to="/tenants" className="mb-3 inline-flex items-center gap-1.5 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Training providers
      </Link>
      <PageHeader
        eyebrow={t.code}
        title={t.trading_name}
        description={
          <span className="flex flex-wrap items-center gap-2">
            {t.legal_name} <StatusBadge status={t.status} />{' '}
            {t.suspension_reason ? <span className="text-danger">— {t.suspension_reason}</span> : null}
          </span>
        }
        actions={
          manage ? (
            <>
              {t.status === 'pending' ? (
                <Button
                  variant="secondary"
                  icon={<ShieldCheck className="size-4" />}
                  onClick={() => act.mutate({ verb: 'verify' })}
                >
                  Mark verified
                </Button>
              ) : null}
              {t.status === 'pending' || t.status === 'verified' ? (
                <Button
                  icon={<CheckCircle2 className="size-4" />}
                  loading={act.isPending}
                  onClick={() => act.mutate({ verb: 'approve' })}
                >
                  Approve & go live
                </Button>
              ) : null}
              {t.status === 'active' ? (
                <Button
                  variant="secondary"
                  icon={<PauseCircle className="size-4" />}
                  onClick={() => setSuspending(true)}
                >
                  Suspend
                </Button>
              ) : null}
              {t.status === 'suspended' ? (
                <Button icon={<PlayCircle className="size-4" />} onClick={() => act.mutate({ verb: 'reactivate' })}>
                  Reactivate
                </Button>
              ) : null}
            </>
          ) : null
        }
      />

      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        {manage ? (
          <DomainsPanel basePath={`/admin/tenants/${id}/domains`} />
        ) : (
          <Card>
            <CardHeader title="Domains" />
          </Card>
        )}
        <div className="grid content-start gap-5">
          {manage ? <InviteOwnerCard tenantId={id} /> : null}
          <Card>
            <CardHeader title="Details" />
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-small">
              <dt className="text-muted">CIPC</dt>
              <dd>{t.registration_number ?? '—'}</dd>
              <dt className="text-muted">Cell</dt>
              <dd>
                <code>{t.cell_id}</code>
              </dd>
              <dt className="text-muted">Centres</dt>
              <dd>{t.centres_count ?? 0}</dd>
              <dt className="text-muted">Package</dt>
              <dd>{t.package_code ?? '—'}</dd>
              <dt className="text-muted">Created</dt>
              <dd>{t.created_at ? new Date(t.created_at).toLocaleString() : '—'}</dd>
            </dl>
          </Card>
        </div>
      </div>

      <SuspendDialog
        open={suspending}
        onOpenChange={setSuspending}
        loading={act.isPending}
        onConfirm={(reason) => act.mutate({ verb: 'suspend', body: { reason } })}
      />
    </>
  );
}

function InviteOwnerCard({ tenantId }: { tenantId: string }) {
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const invite = useMutation({
    mutationFn: () => call('POST', `/admin/tenants/${tenantId}/invitations`, { email, role: 'tp-owner' }),
    onSuccess: () => {
      toast(`Invitation sent to ${email}`);
      setEmail('');
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    invite.mutate();
  };

  return (
    <Card>
      <CardHeader title="Invite the owner" subtitle="They set up their team, brand and domain themselves." />
      <form onSubmit={submit} className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label="Owner email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="owner@provider.co.za"
        />
        <Button type="submit" variant="secondary" icon={<Mail className="size-4" />} loading={invite.isPending}>
          Send invitation
        </Button>
      </form>
    </Card>
  );
}

function SuspendDialog({
  open,
  onOpenChange,
  onConfirm,
  loading,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onConfirm: (reason: string) => void;
  loading: boolean;
}) {
  const [reason, setReason] = useState('');
  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title="Suspend provider"
      description="Everyone at this provider loses access immediately. You can reactivate at any time."
      footer={
        <>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            variant="danger"
            loading={loading}
            disabled={reason.trim().length < 5}
            onClick={() => onConfirm(reason)}
          >
            Suspend
          </Button>
        </>
      }
    >
      <Field
        label="Reason (shown in the audit log)"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Accreditation lapsed"
      />
    </Dialog>
  );
}
