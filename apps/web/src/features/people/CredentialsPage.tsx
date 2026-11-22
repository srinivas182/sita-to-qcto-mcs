import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BadgeCheck, Plus } from 'lucide-react';
import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  PageHeader,
  Select,
  Skeleton,
  Table,
  cx,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { CredentialDialog } from './CredentialDialog';
import { credentialState, ROLES, type Credential } from './types';

const shortDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const filters = [
  ['', 'All'],
  ['pending', 'To check'],
  ['verified', 'Verified'],
  ['rejected', 'Not accepted'],
] as const;

/** Who is registered to facilitate, assess, moderate or mentor what — and until when. */
export function CredentialsPage() {
  const can = useCan();
  const qc = useQueryClient();
  const toast = useToast();
  const [status, setStatus] = useState<string>('');
  const [role, setRole] = useState('');
  const [adding, setAdding] = useState(false);
  const [rejecting, setRejecting] = useState<Credential | null>(null);
  const q = useQuery({
    queryKey: ['credentials', 'register', status, role],
    queryFn: () =>
      call<{ data: Credential[]; counts: Record<string, number> }>('GET', '/people/credentials', undefined, {
        status: status || undefined,
        role: role || undefined,
      }),
  });
  const verify = useMutation({
    mutationFn: (c: Credential) => call('POST', `/credentials/${c.id}/verify`),
    onSuccess: () => {
      toast('Verified');
      void qc.invalidateQueries({ queryKey: ['credentials'] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const counts = q.data?.counts ?? {};
  const attention = (counts.expired ?? 0) + (counts.expiring ?? 0);

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Registrations"
        description="Facilitators, assessors, moderators and mentors, with the body that registered them, what they may do and until when. Only checked, in-date registrations let someone be assigned."
        actions={
          can('credentials.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
              Add registration
            </Button>
          ) : null
        }
      />
      {attention ? (
        <div className="mb-4">
          <Alert tone="warning">
            {counts.expired ? `${counts.expired} expired` : ''}
            {counts.expired && counts.expiring ? ' and ' : ''}
            {counts.expiring ? `${counts.expiring} ending within 90 days` : ''}. People are emailed at 90, 60 and 30
            days.
          </Alert>
        </div>
      ) : null}
      <Card>
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="flex gap-1 rounded-lg bg-line-soft p-1">
            {filters.map(([key, label]) => (
              <button
                key={key}
                type="button"
                aria-pressed={status === key}
                onClick={() => setStatus(key)}
                className={cx(
                  'rounded-md px-3 py-1 text-small',
                  status === key ? 'bg-surface text-ink shadow-sm' : 'text-muted',
                )}
              >
                {label}
                {key === 'pending' && counts.pending ? (
                  <span className="ml-1.5 tabular-nums text-faint">{counts.pending}</span>
                ) : null}
              </button>
            ))}
          </div>
          <div className="w-48">
            <Select label="Role" value={role} onChange={(e) => setRole(e.target.value)}>
              <option value="">All roles</option>
              {Object.entries(ROLES).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          </div>
        </div>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(c) => c.id}
            empty={
              <EmptyState icon={<BadgeCheck className="size-5" />} title="No registrations yet">
                Staff can add their own from My profile; you check them here.
              </EmptyState>
            }
            columns={[
              {
                key: 'person',
                header: 'Person',
                cell: (c) => (
                  <div className="min-w-0">
                    <div className="font-medium text-ink">{c.person}</div>
                    <div className="text-small text-muted">{c.role_label}</div>
                  </div>
                ),
              },
              {
                key: 'reg',
                header: 'Registration',
                cell: (c) => (
                  <span className="text-small">
                    {c.body} · <span className="tabular-nums">{c.registration_number}</span>
                  </span>
                ),
              },
              {
                key: 'scope',
                header: 'Covers',
                cell: (c) => (
                  <span className="text-small" title={c.qualifications.map((x) => x.title).join('\n')}>
                    {c.qualifications.length === 1
                      ? c.qualifications[0]!.title
                      : `${c.qualifications.length} qualifications`}
                  </span>
                ),
              },
              {
                key: 'until',
                header: 'Until',
                cell: (c) => <span className="whitespace-nowrap tabular-nums">{shortDate(c.expires_on)}</span>,
              },
              {
                key: 'state',
                header: 'Status',
                cell: (c) => (
                  <Badge tone={credentialState[c.state].tone} dot>
                    {credentialState[c.state].label}
                  </Badge>
                ),
              },
              {
                key: 'actions',
                header: <span className="sr-only">Actions</span>,
                cell: (c) =>
                  c.status === 'pending' && can('credentials.manage') ? (
                    <div className="flex justify-end gap-2 whitespace-nowrap">
                      <Button size="sm" variant="secondary" onClick={() => setRejecting(c)}>
                        Not accepted
                      </Button>
                      <Button
                        size="sm"
                        loading={verify.isPending && verify.variables?.id === c.id}
                        onClick={() => verify.mutate(c)}
                      >
                        Verify
                      </Button>
                    </div>
                  ) : null,
              },
            ]}
          />
        )}
      </Card>
      {adding ? <AddForPerson onClose={() => setAdding(false)} /> : null}
      {rejecting ? <RejectDialog credential={rejecting} onClose={() => setRejecting(null)} /> : null}
    </>
  );
}

function AddForPerson({ onClose }: { onClose: () => void }) {
  const [person, setPerson] = useState('');
  const [chosen, setChosen] = useState<string | null>(null);
  const people = useQuery({
    queryKey: ['users', 'all'],
    queryFn: () =>
      call<{ data: { id: string; name: string }[] }>('GET', '/users', undefined, { per_page: 100 }).then((r) => r.data),
  });
  if (chosen) return <CredentialDialog personId={chosen} credential={null} onClose={onClose} />;

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Add a registration"
      description="Whose registration is it?"
    >
      <div className="grid gap-3">
        <Select label="Person" value={person} onChange={(e) => setPerson(e.target.value)}>
          <option value="">Choose…</option>
          {people.data?.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!person} onClick={() => setChosen(person)}>
            Next
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

function RejectDialog({ credential, onClose }: { credential: Credential; onClose: () => void }) {
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const m = useMutation({
    mutationFn: () => call('POST', `/credentials/${credential.id}/reject`, { reason }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['credentials'] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Registration not accepted"
      description={`${credential.person} sees this reason and can correct the registration.`}
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          m.mutate();
        }}
      >
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Reason</span>
          <textarea
            required
            minLength={5}
            className="min-h-24 rounded-lg border border-line bg-surface p-2"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </label>
        {m.error ? <Alert tone="danger">{errorMessage(m.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant="danger" loading={m.isPending}>
            Not accepted
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
