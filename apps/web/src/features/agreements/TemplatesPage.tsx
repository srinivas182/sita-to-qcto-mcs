import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from '@tanstack/react-router';
import { ArrowLeft, FileText, Plus } from 'lucide-react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  Field,
  PageHeader,
  Skeleton,
  Table,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { KIND_LABEL, ROLE_LABEL, when, type Template, type TemplateFamily } from './types';

interface Settings {
  advanced_by_default: boolean;
  valid_days: number;
  advanced_available: boolean;
}

/** The provider's agreement wording, with versions, and how agreements are signed. */
export function TemplatesPage() {
  const can = useCan();
  const manage = can('agreements.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const q = useQuery({
    queryKey: ['agreement-templates'],
    queryFn: () =>
      call<{ data: TemplateFamily[]; starters: { key: string; name: string; kind: string }[] }>(
        'GET',
        '/agreement-templates',
      ),
  });
  const settings = useQuery({
    queryKey: ['agreement-settings'],
    queryFn: () => call<{ data: Settings }>('GET', '/agreement-settings'),
  });
  const start = useMutation({
    mutationFn: (starter: string) => call<{ data: Template }>('POST', '/agreement-templates', { starter }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['agreement-templates'] });
      void navigate({ to: '/agreements/templates/$id', params: { id: r.data.id } });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const save = useMutation({
    mutationFn: (body: Partial<Settings>) => call('PATCH', '/agreement-settings', body),
    onSuccess: () => {
      toast('Saved');
      void qc.invalidateQueries({ queryKey: ['agreement-settings'] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const s = settings.data?.data;
  const used = new Set((q.data?.data ?? []).map((t) => t.kind));

  return (
    <>
      <Link to="/agreements" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Agreements
      </Link>
      <PageHeader
        eyebrow="Agreements"
        title="Wording"
        description="Your agreements, with fields we fill in for each learner and employer. A published version never changes: editing it starts the next version, and every agreement keeps the version it was sent with."
      />
      <div className="grid gap-4">
        <Card>
          {q.isPending ? (
            <Skeleton className="h-32" />
          ) : (
            <Table
              rows={q.data?.data ?? []}
              rowKey={(t) => t.family_id}
              empty={
                <EmptyState icon={<FileText className="size-5" />} title="No wording yet">
                  Start from a standard agreement below and adjust it to your SETA's requirements.
                </EmptyState>
              }
              columns={[
                {
                  key: 'name',
                  header: 'Agreement',
                  cell: (t) => (
                    <div className="min-w-0">
                      <Link
                        to="/agreements/templates/$id"
                        params={{ id: t.id }}
                        className="font-medium text-ink hover:underline"
                      >
                        {t.name}
                      </Link>
                      <div className="text-small text-muted">
                        {KIND_LABEL[t.kind]} · signed by {t.parties.map((p) => ROLE_LABEL[p].toLowerCase()).join(', ')}
                      </div>
                    </div>
                  ),
                },
                {
                  key: 'version',
                  header: 'Version',
                  cell: (t) => (
                    <span className="text-small">
                      {t.published_version ? `${t.published_version} in use` : 'None published'}
                      {t.status === 'draft' ? ` · draft ${t.version}` : ''}
                    </span>
                  ),
                },
                {
                  key: 'signature',
                  header: 'Signature',
                  cell: (t) => (
                    <Badge tone={t.advanced_signature ? 'primary' : 'neutral'}>
                      {t.advanced_signature ? 'Advanced (LAWtrust)' : 'PIN-confirmed'}
                    </Badge>
                  ),
                },
                {
                  key: 'updated',
                  header: 'Changed',
                  cell: (t) => <span className="text-small whitespace-nowrap">{when(t.updated_at)}</span>,
                },
              ]}
            />
          )}
          {manage && q.data ? (
            <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
              {q.data.starters.map((st) => (
                <Button
                  key={st.key}
                  size="sm"
                  variant="secondary"
                  icon={<Plus className="size-4" />}
                  loading={start.isPending && start.variables === st.key}
                  onClick={() => start.mutate(st.key)}
                >
                  {used.has(st.kind) ? 'Another: ' : 'Start from: '}
                  {st.name}
                </Button>
              ))}
            </div>
          ) : null}
          <p className="mt-3 text-small text-muted">
            The standard wording is a starting point. Check it against your SETA's requirements and your own legal
            advice before you publish it.
          </p>
        </Card>

        <Card>
          <CardHeader title="How agreements are signed" />
          {s ? (
            <div className="grid gap-4">
              <label className="flex items-start gap-3 text-small">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={s.advanced_by_default}
                  disabled={!manage || save.isPending}
                  onChange={(e) => save.mutate({ advanced_by_default: e.target.checked })}
                />
                <span>
                  <span className="font-medium text-ink">Use advanced e-signatures for new wording</span>
                  <span className="block text-muted">
                    For agreements where a SETA or a law asks for an advanced electronic signature. These go through
                    LAWtrust, an accredited South African provider, by email. You can still choose for each agreement.
                  </span>
                </span>
              </label>
              {!s.advanced_available ? (
                <Alert tone="info">
                  LAWtrust is not connected yet. Advanced agreements can be written but not sent until the platform team
                  connects it.
                </Alert>
              ) : null}
              <div className="max-w-48">
                <Field
                  label="Links work for (days)"
                  type="number"
                  min={3}
                  max={90}
                  defaultValue={s.valid_days}
                  disabled={!manage}
                  onBlur={(e) =>
                    Number(e.target.value) !== s.valid_days && save.mutate({ valid_days: Number(e.target.value) })
                  }
                  hint="Reminders go on day 3 and day 7."
                />
              </div>
            </div>
          ) : (
            <Skeleton className="h-20" />
          )}
        </Card>
      </div>
    </>
  );
}
