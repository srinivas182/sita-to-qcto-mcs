import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { UserPlus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  Dialog,
  Field,
  PageHeader,
  Select,
  Skeleton,
  Table,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

interface Staff {
  id: string;
  name: string;
  email: string | null;
  roles: string[];
  last_login_at: string | null;
}

const platformRoles = [
  ['super-admin', 'Super Admin'],
  ['jv-compliance', 'JV compliance desk'],
  ['qcto-officer', 'QCTO officer'],
  ['ceta-officer', 'CETA officer'],
  ['public-verifier', 'Verifier'],
] as const;

export function StaffPage() {
  const qc = useQueryClient();
  const toast = useToast();
  const list = useQuery({
    queryKey: ['admin', 'staff'],
    queryFn: () => call<{ data: Staff[] }>('GET', '/admin/staff').then((r) => r.data),
  });
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', role: 'super-admin' });
  const [error, setError] = useState<string | null>(null);
  const add = useMutation({
    mutationFn: () => call('POST', '/admin/staff', form),
    onSuccess: () => {
      toast('Access granted — a set-password email is on its way');
      setOpen(false);
      void qc.invalidateQueries({ queryKey: ['admin', 'staff'] });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    add.mutate();
  };

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Platform staff"
        description="People who work across providers: the JV team, regulators and verifiers. Two-step verification is mandatory."
        actions={
          <Button icon={<UserPlus className="size-4" />} onClick={() => setOpen(true)}>
            Add staff
          </Button>
        }
      />
      <Card>
        {list.isPending ? (
          <Skeleton className="h-32" />
        ) : (
          <Table
            rows={list.data ?? []}
            rowKey={(s) => s.id}
            columns={[
              {
                key: 'name',
                header: 'Name',
                cell: (s) => (
                  <div>
                    <div className="font-medium">{s.name}</div>
                    <div className="text-small text-muted">{s.email}</div>
                  </div>
                ),
              },
              {
                key: 'roles',
                header: 'Roles',
                cell: (s) => (
                  <div className="flex flex-wrap gap-1">
                    {s.roles.map((r) => (
                      <Badge key={r} tone="primary">
                        {r.replace(/-/g, ' ')}
                      </Badge>
                    ))}
                  </div>
                ),
              },
              {
                key: 'last',
                header: 'Last sign-in',
                cell: (s) => (
                  <span className="text-muted">
                    {s.last_login_at ? new Date(s.last_login_at).toLocaleString() : 'Never'}
                  </span>
                ),
              },
            ]}
          />
        )}
      </Card>
      <Dialog open={open} onOpenChange={setOpen} title="Add platform staff">
        <form onSubmit={submit} className="grid gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="First name"
              required
              value={form.first_name}
              onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            />
            <Field
              label="Last name"
              required
              value={form.last_name}
              onChange={(e) => setForm({ ...form, last_name: e.target.value })}
            />
          </div>
          <Field
            label="Work email"
            type="email"
            required
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
          />
          <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
            {platformRoles.map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </Select>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button type="submit" loading={add.isPending}>
              Grant access
            </Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}
