import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { UserPlus, Users } from 'lucide-react';
import { useDeferredValue, useState, type FormEvent } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Skeleton,
  StatusBadge,
  Table,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { useCan, useMe } from '@/lib/session';

interface Member {
  id: string;
  name: string;
  email: string | null;
  phone: string | null;
  status: string;
  roles: Array<{ role: string; centre_id: string | null }>;
}
interface Role {
  key: string;
  label: string;
  scope: string;
  assignable: boolean;
}

export function UsersPage() {
  const me = useMe();
  const can = useCan();
  const [search, setSearch] = useState('');
  const q = useDeferredValue(search);
  const [inviting, setInviting] = useState(false);
  const [editing, setEditing] = useState<Member | null>(null);
  const roles = useQuery({
    queryKey: ['roles'],
    queryFn: () => call<{ data: Role[] }>('GET', '/roles').then((r) => r.data),
    staleTime: 10 * 60_000,
  });
  const labels = new Map((roles.data ?? []).map((r) => [r.key, r.label]));
  const people = useQuery({
    queryKey: ['users', q],
    queryFn: () =>
      call<{ data: Member[]; meta: { total: number } }>('GET', '/users', undefined, {
        search: q || undefined,
        per_page: 50,
      }),
    placeholderData: keepPreviousData,
  });

  return (
    <>
      <PageHeader
        eyebrow={me.tenant?.name}
        title="Users & roles"
        description="Invite your team and learners. Roles can be limited to one centre — a facilitator in Soweto sees only Soweto."
        actions={
          can('tenant.users.invite') ? (
            <Button icon={<UserPlus className="size-4" />} onClick={() => setInviting(true)}>
              Invite
            </Button>
          ) : null
        }
      />
      <Card>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search name, email or phone"
          aria-label="Search people"
          className="mb-4 h-9 w-full rounded-lg border border-line bg-surface px-3 text-body sm:max-w-xs"
        />
        {people.isPending ? (
          <div className="grid gap-2">
            {Array.from({ length: 5 }, (_, i) => (
              <Skeleton key={i} className="h-11" />
            ))}
          </div>
        ) : (
          <Table
            rows={people.data?.data ?? []}
            rowKey={(m) => m.id}
            onRowClick={can('tenant.roles.assign') ? (m) => m.id !== me.user.id && setEditing(m) : undefined}
            empty={
              <EmptyState icon={<Users className="size-5" />} title="No people yet">
                Invite your first team member.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Name',
                cell: (m) => (
                  <div>
                    <div className="font-medium">{m.name}</div>
                    <div className="text-small text-muted">{m.email ?? m.phone}</div>
                  </div>
                ),
              },
              {
                key: 'roles',
                header: 'Roles',
                cell: (m) => (
                  <div className="flex flex-wrap gap-1">
                    {m.roles.map((r) => (
                      <Badge key={r.role + (r.centre_id ?? '')} tone="primary">
                        {labels.get(r.role) ?? r.role}
                        {r.centre_id ? ' · one centre' : ''}
                      </Badge>
                    ))}
                  </div>
                ),
              },
              { key: 'status', header: 'Status', cell: (m) => <StatusBadge status={m.status} /> },
            ]}
          />
        )}
      </Card>
      <InviteDialog open={inviting} onOpenChange={setInviting} roles={(roles.data ?? []).filter((r) => r.assignable)} />
      {editing ? (
        <RolesDialog
          member={editing}
          roles={(roles.data ?? []).filter((r) => r.assignable)}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </>
  );
}

function InviteDialog({
  open,
  onOpenChange,
  roles,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  roles: Role[];
}) {
  const me = useMe();
  const toast = useToast();
  const qc = useQueryClient();
  const [form, setForm] = useState({ first_name: '', email: '', phone: '', role: 'facilitator' });
  const [error, setError] = useState<string | null>(null);
  const invite = useMutation({
    mutationFn: () =>
      call('POST', `/tenants/${me.tenant?.id}/invitations`, {
        ...form,
        email: form.email || null,
        phone: form.phone || null,
      }),
    onSuccess: () => {
      toast('Invitation sent');
      onOpenChange(false);
      setForm({ first_name: '', email: '', phone: '', role: form.role });
      void qc.invalidateQueries({ queryKey: ['users'] });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    invite.mutate();
  };

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title="Invite someone"
      description="Send by email, or by WhatsApp to a mobile number — ideal for learners and site staff."
    >
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label="First name"
          value={form.first_name}
          onChange={(e) => setForm({ ...form, first_name: e.target.value })}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Email"
            type="email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
          <Field
            label="or mobile (WhatsApp)"
            type="tel"
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
            placeholder="082 123 4567"
          />
        </div>
        <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
          {roles.map((r) => (
            <option key={r.key} value={r.key}>
              {r.label}
            </option>
          ))}
        </Select>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" loading={invite.isPending}>
            Send invitation
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function RolesDialog({ member, roles, onClose }: { member: Member; roles: Role[]; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [selected, setSelected] = useState(() => new Set(member.roles.map((r) => r.role)));
  const save = useMutation({
    mutationFn: () =>
      call('PUT', `/users/${member.id}/roles`, {
        roles: [...selected].map((role) => ({
          role,
          centre_id: member.roles.find((r) => r.role === role)?.centre_id ?? null,
        })),
      }),
    onSuccess: () => {
      toast('Roles updated');
      void qc.invalidateQueries({ queryKey: ['users'] });
      onClose();
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Roles for ${member.name}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save roles
          </Button>
        </>
      }
    >
      <div className="grid gap-1">
        {roles.map((r) => (
          <label key={r.key} className="flex items-center gap-3 rounded-lg px-3 py-2.5 hover:bg-surface-muted">
            <input
              type="checkbox"
              className="size-4 accent-primary-700"
              checked={selected.has(r.key)}
              onChange={(e) => {
                const next = new Set(selected);
                if (e.target.checked) next.add(r.key);
                else next.delete(r.key);
                setSelected(next);
              }}
            />
            <span className="flex-1">{r.label}</span>
            <Badge>{r.scope}</Badge>
          </label>
        ))}
      </div>
    </Dialog>
  );
}
