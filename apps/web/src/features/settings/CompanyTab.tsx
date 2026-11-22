import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Field, Select, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';

type Company = Record<string, string | number | undefined>;

const provinces = [
  'Eastern Cape',
  'Free State',
  'Gauteng',
  'KwaZulu-Natal',
  'Limpopo',
  'Mpumalanga',
  'North West',
  'Northern Cape',
  'Western Cape',
];

export function CompanyTab() {
  const query = useQuery({
    queryKey: ['admin', 'settings', 'company'],
    queryFn: () => call<{ data: Company; complete: boolean }>('GET', '/admin/settings/company'),
  });

  if (query.isPending || !query.data) return <Skeleton className="h-96" />;

  return <CompanyForm initial={query.data.data} complete={query.data.complete} />;
}

function CompanyForm({ initial, complete }: { initial: Company; complete: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [form, setForm] = useState<Company>(initial);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => call<{ data: Company; complete: boolean }>('PUT', '/admin/settings/company', form),
    onSuccess: (res) => {
      toast('Company details saved');
      qc.setQueryData(['admin', 'settings', 'company'], res);
    },
    onError: (e) => {
      setError(errorMessage(e));
      if (e instanceof ApiError) setErrors(e.fields);
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrors({});
    save.mutate();
  };

  const field = (name: string, label: string, props: Partial<Parameters<typeof Field>[0]> = {}) => (
    <Field
      label={label}
      value={String(form[name] ?? '')}
      onChange={(e) => setForm({ ...form, [name]: e.target.value })}
      error={errors[name]?.[0]}
      {...props}
    />
  );

  return (
    <Card>
      <CardHeader
        title="Company details"
        subtitle="The supplier details SARS requires on a tax invoice."
        action={complete ? <Badge tone="success">Ready for invoices</Badge> : <Badge tone="warning">Incomplete</Badge>}
      />
      <form onSubmit={submit} className="grid gap-5">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="sr-only">Company</legend>
          {field('registered_name', 'Registered name', {
            required: true,
            className: 'sm:col-span-2',
            placeholder: 'TYM MCS Skills JV (Pty) Ltd',
          })}
          {field('trading_name', 'Trading name')}
          {field('registration_number', 'Company registration number', { placeholder: '2026/123456/07' })}
          {field('vat_number', 'VAT number', {
            inputMode: 'numeric',
            placeholder: '4xxxxxxxxx',
            hint: '10 digits, starting with 4. Leave empty if not VAT-registered.',
          })}
          {field('vat_rate', 'VAT rate (%)', { type: 'number', step: '0.01', min: 0, max: 100, required: true })}
        </fieldset>
        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-1 text-small font-semibold text-ink">Address</legend>
          {field('address_line1', 'Street address', { required: true, className: 'sm:col-span-2' })}
          {field('address_line2', 'Address line 2', { className: 'sm:col-span-2' })}
          {field('city', 'City', { required: true })}
          <Select
            label="Province"
            value={String(form.province ?? '')}
            onChange={(e) => setForm({ ...form, province: e.target.value })}
          >
            <option value="">Choose…</option>
            {provinces.map((p) => (
              <option key={p}>{p}</option>
            ))}
          </Select>
          {field('postal_code', 'Postal code', { inputMode: 'numeric' })}
          <Select
            label="Country"
            value={String(form.country ?? 'ZA')}
            onChange={(e) => setForm({ ...form, country: e.target.value })}
          >
            <option value="ZA">South Africa</option>
          </Select>
        </fieldset>
        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-1 text-small font-semibold text-ink">Contact and invoices</legend>
          {field('email', 'Accounts email', { type: 'email', required: true })}
          {field('phone', 'Phone', { type: 'tel' })}
          {field('website', 'Website', { type: 'url', placeholder: 'https://' })}
          {field('invoice_prefix', 'Invoice number prefix', {
            required: true,
            hint: 'e.g. SKL gives SKL-000123',
            onChange: (e) => setForm({ ...form, invoice_prefix: e.target.value.toUpperCase() }),
          })}
          <Select
            label="Currency"
            value={String(form.currency ?? 'ZAR')}
            onChange={(e) => setForm({ ...form, currency: e.target.value })}
          >
            <option value="ZAR">South African rand (ZAR)</option>
          </Select>
          <div className="grid gap-1.5 sm:col-span-2">
            <label htmlFor="bank" className="text-small font-medium">
              Bank details for EFT (printed on invoices)
            </label>
            <textarea
              id="bank"
              rows={3}
              maxLength={1000}
              value={String(form.bank_details ?? '')}
              onChange={(e) => setForm({ ...form, bank_details: e.target.value })}
              className="rounded-lg border border-line bg-surface px-3 py-2 text-body focus:border-primary-500 focus:outline-none focus:ring-4 focus:ring-primary-100"
            />
          </div>
        </fieldset>
        <div>
          <Button type="submit" loading={save.isPending}>
            Save company details
          </Button>
        </div>
      </form>
    </Card>
  );
}
