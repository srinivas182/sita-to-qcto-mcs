import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LocateFixed, MapPin, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
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
  type Tone,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { provinces } from '@/features/apply/types';

interface Centre {
  id: string;
  name: string;
  status: 'active' | 'inactive';
  accreditation_type: 'sdp' | 'ac';
  accreditation_number: string | null;
  accreditation_expires_on: string | null;
  days_to_expiry: number | null;
  expiry_state: 'ok' | 'soon' | 'critical' | 'expired' | 'unknown';
  scope: string[];
  address_line1: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  latitude: number | null;
  longitude: number | null;
}

const expiry: Record<Centre['expiry_state'], [Tone, (d: number | null) => string]> = {
  ok: ['success', () => 'Valid'],
  soon: ['warning', (d) => `Ends in ${d} days`],
  critical: ['danger', (d) => `Ends in ${d} days`],
  expired: ['danger', () => 'Expired'],
  unknown: ['neutral', () => 'No expiry set'],
};

/** Accredited delivery sites, with scope and expiry alerts at 90, 60 and 30 days. */
export function CentresPage() {
  const can = useCan();
  const q = useQuery({
    queryKey: ['centres'],
    queryFn: () => call<{ data: Centre[] }>('GET', '/centres').then((r) => r.data),
  });
  const [editing, setEditing] = useState<Centre | 'new' | null>(null);
  const attention = (q.data ?? []).filter((c) => ['soon', 'critical', 'expired'].includes(c.expiry_state));

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Centres"
        description="Each accredited site, its QCTO number and the qualifications it may offer. Owners and centre managers are emailed 90, 60 and 30 days before accreditation ends."
        actions={
          can('centres.manage') ? (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              Add centre
            </Button>
          ) : null
        }
      />
      {attention.length ? (
        <div className="mb-4">
          <Alert tone="warning">
            {attention.length === 1
              ? `${attention[0]!.name} needs re-accreditation soon.`
              : `${attention.length} centres need re-accreditation soon.`}{' '}
            Learners on programmes outside a valid accreditation cannot be certificated.
          </Alert>
        </div>
      ) : null}
      <Card>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data ?? []}
            rowKey={(c) => c.id}
            onRowClick={can('centres.manage') ? (c) => setEditing(c) : undefined}
            empty={<EmptyState icon={<MapPin className="size-5" />} title="No centres yet" />}
            columns={[
              {
                key: 'name',
                header: 'Centre',
                cell: (c) => (
                  <div>
                    <div className="font-medium text-ink">{c.name}</div>
                    <div className="text-small text-muted">
                      {[c.city, c.province].filter(Boolean).join(', ') || '—'}
                    </div>
                  </div>
                ),
              },
              {
                key: 'number',
                header: 'Accreditation',
                cell: (c) => <span className="tabular-nums">{c.accreditation_number ?? '—'}</span>,
              },
              {
                key: 'scope',
                header: 'Scope',
                cell: (c) => (c.scope.length ? `${c.scope.length} qualification${c.scope.length > 1 ? 's' : ''}` : '—'),
              },
              {
                key: 'expiry',
                header: 'Expiry',
                cell: (c) => {
                  const [tone, label] = expiry[c.expiry_state];
                  return (
                    <span className="grid gap-0.5">
                      <Badge tone={tone} dot>
                        {label(c.days_to_expiry)}
                      </Badge>
                      {c.accreditation_expires_on ? (
                        <span className="text-small text-muted">{c.accreditation_expires_on}</span>
                      ) : null}
                    </span>
                  );
                },
              },
              {
                key: 'status',
                header: 'Status',
                cell: (c) => <Badge tone={c.status === 'active' ? 'success' : 'neutral'}>{c.status}</Badge>,
              },
            ]}
          />
        )}
      </Card>
      {editing ? <CentreDialog centre={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </>
  );
}

function CentreDialog({ centre, onClose }: { centre: Centre | null; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: centre?.name ?? '',
    status: centre?.status ?? 'active',
    accreditation_type: centre?.accreditation_type ?? 'sdp',
    accreditation_number: centre?.accreditation_number ?? '',
    accreditation_expires_on: centre?.accreditation_expires_on ?? '',
    scope: centre?.scope.join(', ') ?? '',
    address_line1: centre?.address_line1 ?? '',
    city: centre?.city ?? '',
    province: centre?.province ?? '',
    postal_code: centre?.postal_code ?? '',
    latitude: centre?.latitude?.toString() ?? '',
    longitude: centre?.longitude?.toString() ?? '',
  });
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);
  const here = () => {
    setLocError(null);
    if (!('geolocation' in navigator)) return setLocError('This browser cannot share its location.');
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (p) => {
        setForm((f) => ({ ...f, latitude: p.coords.latitude.toFixed(6), longitude: p.coords.longitude.toFixed(6) }));
        setLocating(false);
      },
      () => {
        setLocError('Location was not shared. Enter it, or allow location for this site.');
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  };
  const pinned = form.latitude !== '' && form.longitude !== '';
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])),
        name: form.name,
        latitude: form.latitude === '' ? null : Number(form.latitude),
        longitude: form.longitude === '' ? null : Number(form.longitude),
        scope: form.scope.split(/[\s,]+/).filter(Boolean),
      };
      return centre ? call('PATCH', `/centres/${centre.id}`, body) : call('POST', '/centres', body);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['centres'] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const f = (k: keyof typeof form, label: string, extra: Record<string, unknown> = {}) => (
    <Field
      label={label}
      value={form[k]}
      onChange={(e) => setForm({ ...form, [k]: e.target.value })}
      error={errors[k]?.[0]}
      {...extra}
    />
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={centre ? 'Edit centre' : 'Add centre'}
      description="Changing the expiry date after re-accreditation restarts the reminders."
    >
      <form
        className="grid gap-3"
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {save.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(save.error)}</Alert> : null}
        {f('name', 'Name', { required: true })}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label="Accreditation type"
            value={form.accreditation_type}
            onChange={(e) => setForm({ ...form, accreditation_type: e.target.value as 'sdp' })}
          >
            <option value="sdp">Skills development provider</option>
            <option value="ac">Assessment centre</option>
          </Select>
          <Select
            label="Status"
            value={form.status}
            onChange={(e) => setForm({ ...form, status: e.target.value as 'active' })}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </div>
        {f('accreditation_number', 'QCTO accreditation number', { placeholder: '07-QCTO/SDP180825105627' })}
        {f('accreditation_expires_on', 'Valid until', { type: 'date' })}
        {f('scope', 'Qualifications in scope (SAQA IDs)', {
          hint: 'Separate with commas.',
          error: Object.entries(errors).find(([k]) => k.startsWith('scope'))?.[1][0],
        })}
        {f('address_line1', 'Street address')}
        <div className="grid gap-3 sm:grid-cols-3">
          {f('city', 'City')}
          <Select
            label="Province"
            value={form.province}
            onChange={(e) => setForm({ ...form, province: e.target.value })}
          >
            <option value="">Choose…</option>
            {provinces.map((p) => (
              <option key={p}>{p}</option>
            ))}
          </Select>
          {f('postal_code', 'Postal code', { inputMode: 'numeric' })}
        </div>
        <fieldset className="grid gap-2">
          <legend className="text-small font-medium text-ink">Map pin (used to check attendance on site)</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            {f('latitude', 'Latitude', { inputMode: 'decimal', placeholder: '-26.2485' })}
            {f('longitude', 'Longitude', { inputMode: 'decimal', placeholder: '27.8540' })}
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<LocateFixed className="size-4" />}
              loading={locating}
              onClick={here}
            >
              Use where I am now
            </Button>
            {pinned ? (
              <a
                className="text-small font-medium text-primary-700 hover:underline"
                target="_blank"
                rel="noreferrer"
                href={`https://www.openstreetmap.org/?mlat=${form.latitude}&mlon=${form.longitude}#map=17/${form.latitude}/${form.longitude}`}
              >
                Check the pin on a map
              </a>
            ) : null}
          </div>
          {locError ? (
            <p className="text-small text-danger" role="alert">
              {locError}
            </p>
          ) : null}
        </fieldset>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
