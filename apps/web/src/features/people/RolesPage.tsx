import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ArrowLeft, KeyRound, Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
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
  Table,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';

interface CustomRole {
  id: string;
  key: string;
  name: string;
  description: string | null;
  scope: 'tenant' | 'centre';
  permissions: string[];
  mfa: boolean;
  based_on: string | null;
  people: number;
}

interface RolesResponse {
  data: CustomRole[];
  permissions: { key: string; label: string }[];
  templates: { key: string; label: string; scope: string; permissions: string[] }[];
}

/** Your own roles, made from the permissions you hold, for jobs the standard roles don't fit. */
export function RolesPage() {
  const can = useCan();
  const q = useQuery({ queryKey: ['custom-roles'], queryFn: () => call<RolesResponse>('GET', '/roles/custom') });
  const [editing, setEditing] = useState<CustomRole | 'new' | null>(null);

  return (
    <>
      <Link to="/users" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden /> Users & roles
      </Link>
      <PageHeader
        eyebrow="Provider"
        title="Your own roles"
        description="For jobs the standard roles don't fit, such as a quality officer. Built from permissions you hold. Roles that can change people, money or records need two-step sign-in."
        actions={
          can('tenant.roles.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              New role
            </Button>
          ) : null
        }
      />
      <Card>
        {q.isPending ? (
          <Skeleton className="h-32" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(r) => r.id}
            onRowClick={can('tenant.roles.manage') ? (r) => setEditing(r) : undefined}
            empty={
              <EmptyState icon={<KeyRound className="size-5" />} title="No roles of your own yet">
                The standard roles cover most teams.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Role',
                cell: (r) => (
                  <div>
                    <div className="font-medium text-ink">{r.name}</div>
                    {r.description ? <div className="text-small text-muted">{r.description}</div> : null}
                  </div>
                ),
              },
              {
                key: 'scope',
                header: 'Applies to',
                cell: (r) => (r.scope === 'centre' ? 'One centre' : 'Whole provider'),
              },
              {
                key: 'perms',
                header: 'Permissions',
                cell: (r) => <span className="tabular-nums">{r.permissions.length}</span>,
              },
              {
                key: 'mfa',
                header: 'Sign-in',
                cell: (r) =>
                  r.mfa ? (
                    <Badge tone="primary">
                      <ShieldCheck className="size-3" aria-hidden /> Two-step
                    </Badge>
                  ) : (
                    'Standard'
                  ),
              },
              { key: 'people', header: 'People', cell: (r) => <span className="tabular-nums">{r.people}</span> },
            ]}
          />
        )}
      </Card>
      {editing && q.data ? (
        <RoleDialog role={editing === 'new' ? null : editing} catalogue={q.data} onClose={() => setEditing(null)} />
      ) : null}
    </>
  );
}

function RoleDialog({
  role,
  catalogue,
  onClose,
}: {
  role: CustomRole | null;
  catalogue: RolesResponse;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: role?.name ?? '',
    description: role?.description ?? '',
    scope: role?.scope ?? 'tenant',
    based_on: role?.based_on ?? '',
  });
  const [perms, setPerms] = useState<string[]>(role?.permissions ?? []);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const done = () => {
    void qc.invalidateQueries({ queryKey: ['custom-roles'] });
    void qc.invalidateQueries({ queryKey: ['roles'] });
    onClose();
  };
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        description: form.description || null,
        based_on: form.based_on || null,
        permissions: perms,
      };
      return role ? call('PATCH', `/roles/custom/${role.id}`, body) : call('POST', '/roles/custom', body);
    },
    onSuccess: done,
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const remove = useMutation({ mutationFn: () => call('DELETE', `/roles/custom/${role!.id}`), onSuccess: done });
  const startFrom = (key: string) => {
    const t = catalogue.templates.find((x) => x.key === key);
    setForm({ ...form, based_on: key, scope: (t?.scope === 'centre' ? 'centre' : 'tenant') as 'tenant' });
    if (t) setPerms(t.permissions);
  };
  const permError = Object.entries(errors).find(([k]) => k.startsWith('permissions'))?.[1][0];

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={role ? `Edit ${role.name}` : 'New role'}
      description="Changes apply at once to everyone who holds the role."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field
          label="Name"
          required
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          error={errors.name?.[0]}
        />
        <Field
          label="What it is for"
          value={form.description}
          onChange={(e) => setForm({ ...form, description: e.target.value })}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          {!role ? (
            <Select label="Start from" value={form.based_on} onChange={(e) => startFrom(e.target.value)}>
              <option value="">Nothing</option>
              {catalogue.templates.map((t) => (
                <option key={t.key} value={t.key}>
                  {t.label}
                </option>
              ))}
            </Select>
          ) : null}
          <Select
            label="Applies to"
            value={form.scope}
            onChange={(e) => setForm({ ...form, scope: e.target.value as 'tenant' })}
          >
            <option value="tenant">The whole provider</option>
            <option value="centre">One centre (chosen when given)</option>
          </Select>
        </div>
        {errors.scope ? <p className="text-small text-danger">{errors.scope[0]}</p> : null}
        <fieldset className="grid gap-1.5">
          <legend className="mb-1 text-small font-medium">Permissions</legend>
          <div className="grid max-h-64 gap-1.5 overflow-auto rounded-lg border border-line p-2">
            {catalogue.permissions.map((p) => (
              <label key={p.key} className="flex items-start gap-2 text-small">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={perms.includes(p.key)}
                  onChange={(e) => setPerms(e.target.checked ? [...perms, p.key] : perms.filter((x) => x !== p.key))}
                />
                <span>{p.label}</span>
              </label>
            ))}
          </div>
          {permError ? <p className="text-small text-danger">{permError}</p> : null}
        </fieldset>
        {save.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(save.error)}</Alert> : null}
        {remove.error ? <Alert tone="danger">{errorMessage(remove.error)}</Alert> : null}
        <div className="flex justify-between gap-2">
          {role ? (
            <Button type="button" variant="ghost" loading={remove.isPending} onClick={() => remove.mutate()}>
              Delete role
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={save.isPending} disabled={perms.length === 0}>
              Save
            </Button>
          </div>
        </div>
      </form>
    </Dialog>
  );
}
