import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  EmptyState,
  Field,
  Kpi,
  PageHeader,
  Select,
  Skeleton,
  Table,
  cx,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money, shortDate } from '@/lib/money';
import { statusCopy, type Invoice, type Package, type PlanStatus } from './types';

interface Overview {
  subscriptions: Record<string, number>;
  mrr_cents: number;
  overdue: { count: number; amount_cents: number };
  collected_this_month: { driver: string; count: number; amount_cents: number }[];
}
interface SubRow {
  id: string;
  tenant: { id: string; name: string | null; code: string | null };
  package: string;
  status: PlanStatus;
  interval: string;
  collection: string;
  trial_ends_at: string | null;
  current_period_end: string | null;
}

const tabs = ['invoices', 'subscriptions', 'packages'] as const;
const invoiceTone = { open: 'warning', overdue: 'danger', paid: 'success', void: 'neutral' } as const;

/** Super Admin: collections at a glance, every invoice, plan and package. */
export function AdminBillingPage() {
  const [tab, setTab] = useState<(typeof tabs)[number]>('invoices');
  const overview = useQuery({
    queryKey: ['admin', 'billing', 'overview'],
    queryFn: () => call<Overview>('GET', '/admin/billing/overview'),
  });
  const o = overview.data;
  const collected = o?.collected_this_month.reduce((n, r) => n + r.amount_cents, 0) ?? 0;

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Billing"
        description="Plans, invoices and payments across all providers. Amounts exclude VAT unless stated."
      />
      <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Monthly recurring (base fees)" value={o ? money(o.mrr_cents) : '—'} />
        <Kpi
          label="Active and trial plans"
          value={o ? String((o.subscriptions.active ?? 0) + (o.subscriptions.trialing ?? 0)) : '—'}
        />
        <Kpi label="Overdue" value={o ? `${money(o.overdue.amount_cents)} · ${o.overdue.count}` : '—'} />
        <Kpi label="Collected this month (incl. VAT)" value={o ? money(collected) : '—'} />
      </div>
      <div role="tablist" aria-label="Billing" className="mb-4 flex gap-1">
        {tabs.map((t) => (
          <button
            key={t}
            role="tab"
            aria-selected={tab === t}
            onClick={() => setTab(t)}
            className={cx(
              'rounded-lg px-3 py-1.5 text-body font-medium capitalize',
              tab === t ? 'bg-primary-50 text-primary-700' : 'text-muted hover:bg-line-soft hover:text-ink',
            )}
          >
            {t}
          </button>
        ))}
      </div>
      {tab === 'invoices' ? <InvoicesTab /> : tab === 'subscriptions' ? <SubscriptionsTab /> : <PackagesTab />}
    </>
  );
}

function InvoicesTab() {
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [acting, setActing] = useState<{ invoice: Invoice; kind: 'eft' | 'credit' } | null>(null);
  const list = useQuery({
    queryKey: ['admin', 'billing', 'invoices', status, search],
    queryFn: () =>
      call<{ data: Invoice[] }>('GET', '/admin/billing/invoices', undefined, {
        status: status || undefined,
        search: search || undefined,
      }).then((r) => r.data),
  });

  return (
    <Card>
      <div className="mb-4 flex flex-wrap gap-3">
        <Field
          label="Find by number or EFT reference"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="min-w-64"
        />
        <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="open">Open</option>
          <option value="overdue">Overdue</option>
          <option value="paid">Paid</option>
        </Select>
      </div>
      {list.isPending ? (
        <Skeleton className="h-40" />
      ) : (list.data ?? []).length === 0 ? (
        <EmptyState icon={<FileText className="size-6" />} title="No invoices found">
          Invoices appear here as providers' trials end and periods renew.
        </EmptyState>
      ) : (
        <Table
          rows={list.data ?? []}
          rowKey={(i) => i.id}
          columns={[
            {
              key: 'n',
              header: 'Invoice',
              cell: (i) => (
                <div>
                  <div className="font-medium">{i.number}</div>
                  <div className="text-small text-muted">{i.eft_reference}</div>
                </div>
              ),
            },
            { key: 't', header: 'Provider', cell: (i) => i.tenant?.name ?? '—' },
            { key: 'd', header: 'Due', cell: (i) => shortDate(i.due_at) },
            {
              key: 'a',
              header: 'Total / due',
              cell: (i) => (
                <div>
                  {money(i.total_cents)}
                  <div className="text-small text-muted">{money(i.amount_due_cents)} due</div>
                </div>
              ),
            },
            { key: 's', header: 'Status', cell: (i) => <Badge tone={invoiceTone[i.status]}>{i.status}</Badge> },
            {
              key: 'x',
              header: '',
              cell: (i) => (
                <div className="flex justify-end gap-1">
                  {i.status === 'open' || i.status === 'overdue' ? (
                    <Button size="sm" variant="secondary" onClick={() => setActing({ invoice: i, kind: 'eft' })}>
                      EFT received
                    </Button>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => setActing({ invoice: i, kind: 'credit' })}>
                    Credit
                  </Button>
                  <a
                    href={`/v1/admin/billing/invoices/${i.id}/pdf`}
                    className="inline-flex h-8 items-center rounded-md px-2 text-small text-muted hover:text-ink"
                    aria-label={`Download ${i.number}`}
                  >
                    PDF
                  </a>
                </div>
              ),
            },
          ]}
        />
      )}
      {acting ? <InvoiceAction {...acting} onClose={() => setActing(null)} /> : null}
    </Card>
  );
}

function InvoiceAction({ invoice, kind, onClose }: { invoice: Invoice; kind: 'eft' | 'credit'; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [amount, setAmount] = useState(kind === 'eft' ? (invoice.amount_due_cents / 100).toFixed(2) : '');
  const [text, setText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => {
      const cents = Math.round(Number(amount.replace(',', '.')) * 100);
      return kind === 'eft'
        ? call('POST', `/admin/billing/invoices/${invoice.id}/eft`, { amount_cents: cents, bank_reference: text })
        : call('POST', `/admin/billing/invoices/${invoice.id}/credit`, { amount_cents: cents, reason: text });
    },
    onSuccess: () => {
      toast(kind === 'eft' ? 'Payment recorded' : 'Credit note issued');
      void qc.invalidateQueries({ queryKey: ['admin', 'billing'] });
      onClose();
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    save.mutate();
  };

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={kind === 'eft' ? `EFT received for ${invoice.number}` : `Credit note for ${invoice.number}`}
      description={
        kind === 'eft'
          ? `${money(invoice.amount_due_cents)} due incl. VAT. Reference ${invoice.eft_reference}.`
          : 'The invoice is never edited: a numbered credit note is issued. Enter the amount excluding VAT.'
      }
    >
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label={kind === 'eft' ? 'Amount received (R, incl. VAT)' : 'Amount to credit (R, excl. VAT)'}
          inputMode="decimal"
          required
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
        />
        <Field
          label={kind === 'eft' ? 'Bank statement reference' : 'Reason (printed on the credit note)'}
          required
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            {kind === 'eft' ? 'Record payment' : 'Issue credit note'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function SubscriptionsTab() {
  const list = useQuery({
    queryKey: ['admin', 'billing', 'subscriptions'],
    queryFn: () => call<{ data: SubRow[] }>('GET', '/admin/billing/subscriptions').then((r) => r.data),
  });
  if (list.isPending) return <Skeleton className="h-40" />;

  return (
    <Card>
      <Table
        rows={list.data ?? []}
        rowKey={(s) => s.id}
        columns={[
          { key: 't', header: 'Provider', cell: (s) => <span className="font-medium">{s.tenant.name}</span> },
          {
            key: 'p',
            header: 'Package',
            cell: (s) => `${s.package} · ${s.interval === 'year' ? 'yearly' : 'monthly'}`,
          },
          {
            key: 's',
            header: 'Status',
            cell: (s) => <Badge tone={statusCopy[s.status].tone}>{statusCopy[s.status].label}</Badge>,
          },
          { key: 'c', header: 'Pays by', cell: (s) => (s.collection === 'auto' ? 'Saved card' : 'Invoice') },
          {
            key: 'n',
            header: 'Next date',
            cell: (s) => shortDate(s.status === 'trialing' ? s.trial_ends_at : s.current_period_end),
          },
        ]}
      />
    </Card>
  );
}

function PackagesTab() {
  const [editing, setEditing] = useState<Package | 'new' | null>(null);
  const list = useQuery({
    queryKey: ['admin', 'billing', 'packages'],
    queryFn: () => call<{ data: Package[] }>('GET', '/admin/billing/packages').then((r) => r.data),
  });

  return (
    <Card>
      <CardHeader
        title="Packages"
        subtitle="Prices exclude VAT. Changes apply to new invoices; existing invoices never change."
        action={
          <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            New package
          </Button>
        }
      />
      {list.isPending ? (
        <Skeleton className="h-32" />
      ) : (
        <Table
          rows={list.data ?? []}
          rowKey={(p) => p.id}
          columns={[
            {
              key: 'n',
              header: 'Package',
              cell: (p) => (
                <div className="flex items-center gap-2 font-medium">
                  {p.name}
                  {p.is_example ? <Badge tone="warning">Example</Badge> : null}
                  {!p.public ? <Badge>Private</Badge> : null}
                  {!p.active ? <Badge>Off</Badge> : null}
                </div>
              ),
            },
            { key: 'b', header: 'Monthly', cell: (p) => money(p.base_fee_cents) },
            {
              key: 'l',
              header: 'Per learner',
              cell: (p) => `${money(p.per_learner_cents)} after ${p.included_learners}`,
            },
            { key: 't', header: 'Trial', cell: (p) => `${p.trial_days} days` },
            {
              key: 'x',
              header: '',
              cell: (p) => (
                <Button size="sm" variant="ghost" onClick={() => setEditing(p)}>
                  Edit
                </Button>
              ),
            },
          ]}
        />
      )}
      {editing ? <PackageDialog pkg={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </Card>
  );
}

function PackageDialog({ pkg, onClose }: { pkg: Package | null; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const rand = (c: number | undefined) => (c == null ? '' : (c / 100).toFixed(2));
  const [f, setF] = useState({
    code: pkg?.code ?? '',
    name: pkg?.name ?? '',
    description: pkg?.description ?? '',
    features: (pkg?.features ?? []).join('\n'),
    base: rand(pkg?.base_fee_cents),
    perLearner: rand(pkg?.per_learner_cents ?? 0),
    included: String(pkg?.included_learners ?? 0),
    discount: String(pkg?.annual_discount_percent ?? 0),
    trial: String(pkg?.trial_days ?? 14),
    is_example: pkg?.is_example ?? false,
    active: pkg?.active ?? true,
    public: pkg?.public ?? true,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const cents = (v: string) => Math.round(Number(v.replace(',', '.') || 0) * 100);
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...(pkg ? {} : { code: f.code }),
        name: f.name,
        description: f.description || null,
        features: f.features
          .split('\n')
          .map((s) => s.trim())
          .filter(Boolean),
        base_fee_cents: cents(f.base),
        per_learner_cents: cents(f.perLearner),
        included_learners: Number(f.included),
        annual_discount_percent: Number(f.discount),
        trial_days: Number(f.trial),
        is_example: f.is_example,
        active: f.active,
        public: f.public,
      };
      return pkg
        ? call('PATCH', `/admin/billing/packages/${pkg.id}`, body)
        : call('POST', '/admin/billing/packages', body);
    },
    onSuccess: () => {
      toast('Package saved');
      void qc.invalidateQueries({ queryKey: ['admin', 'billing', 'packages'] });
      onClose();
    },
    onError: (e) => (e instanceof ApiError ? setErrors(e.fields) : toast(errorMessage(e), 'error')),
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={pkg ? `Edit ${pkg.name}` : 'New package'}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="grid gap-3"
      >
        <div className="grid gap-3 sm:grid-cols-2">
          {!pkg ? (
            <Field
              label="Code"
              required
              value={f.code}
              onChange={(e) => setF({ ...f, code: e.target.value.toLowerCase() })}
              error={errors.code?.[0]}
              hint="Lower case, e.g. growth"
            />
          ) : null}
          <Field
            label="Name"
            required
            value={f.name}
            onChange={(e) => setF({ ...f, name: e.target.value })}
            error={errors.name?.[0]}
          />
          <Field
            label="Monthly fee (R excl. VAT)"
            inputMode="decimal"
            required
            value={f.base}
            onChange={(e) => setF({ ...f, base: e.target.value })}
            error={errors.base_fee_cents?.[0]}
          />
          <Field
            label="Per active learner (R a month)"
            inputMode="decimal"
            value={f.perLearner}
            onChange={(e) => setF({ ...f, perLearner: e.target.value })}
          />
          <Field
            label="Learners included"
            inputMode="numeric"
            value={f.included}
            onChange={(e) => setF({ ...f, included: e.target.value })}
          />
          <Field
            label="Yearly discount (%)"
            inputMode="numeric"
            value={f.discount}
            onChange={(e) => setF({ ...f, discount: e.target.value })}
          />
          <Field
            label="Trial days"
            inputMode="numeric"
            value={f.trial}
            onChange={(e) => setF({ ...f, trial: e.target.value })}
          />
        </div>
        <Field
          label="Short description"
          value={f.description}
          onChange={(e) => setF({ ...f, description: e.target.value })}
        />
        <div className="grid gap-1.5">
          <label htmlFor="features" className="text-small font-medium">
            Features (one per line)
          </label>
          <textarea
            id="features"
            rows={4}
            value={f.features}
            onChange={(e) => setF({ ...f, features: e.target.value })}
            className="rounded-lg border border-line px-3 py-2 text-body focus:border-primary-500 focus:outline-none focus:ring-4 focus:ring-primary-100"
          />
        </div>
        <div className="flex flex-wrap gap-4 text-body">
          {(['active', 'public', 'is_example'] as const).map((k) => (
            <label key={k} className="flex items-center gap-2">
              <input
                type="checkbox"
                className="size-4 accent-primary-700"
                checked={f[k]}
                onChange={(e) => setF({ ...f, [k]: e.target.checked })}
              />
              {k === 'active' ? 'Offered' : k === 'public' ? 'Shown on the price list' : 'Mark as example pricing'}
            </label>
          ))}
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save package
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
