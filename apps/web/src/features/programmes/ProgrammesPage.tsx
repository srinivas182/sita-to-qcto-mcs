import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpen, MessageSquarePlus, Plus, Sparkles } from 'lucide-react';
import { Link } from '@tanstack/react-router';
import { useDeferredValue, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  Dialog,
  EmptyState,
  Field,
  PageHeader,
  Skeleton,
  Table,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { money } from '@/lib/money';
import { useCan } from '@/lib/session';
import { EnrolmentBadge } from '@/features/catalogue/EnrolmentBadge';
import type { QualificationDetail, QualificationSummary } from '@/features/catalogue/types';

interface ProgrammeRow {
  id: string;
  active: boolean;
  modes: string[];
  price_cents: number | null;
  qualification: QualificationSummary;
  centres: { id: string; name: string }[];
}

const MODES = ['contact', 'blended', 'online', 'workplace'] as const;

/** What the provider offers, picked from the shared catalogue. */
export function ProgrammesPage() {
  const can = useCan();
  const materials = can('materials.manage');
  const manage = can('programmes.manage');
  const [browsing, setBrowsing] = useState(false);
  const [suggesting, setSuggesting] = useState(false);
  const [adding, setAdding] = useState<QualificationSummary | null>(null);
  const q = useQuery({
    queryKey: ['programmes'],
    queryFn: () => call<{ data: ProgrammeRow[]; suggested: QualificationSummary[] }>('GET', '/programmes'),
  });
  const attention = (q.data?.data ?? []).filter((p) => ['closing', 'closed'].includes(p.qualification.enrolment));

  return (
    <>
      <PageHeader
        eyebrow="Provider"
        title="Programmes"
        description="The qualifications you offer, from the shared catalogue: modules, credits and gazetted dates are kept up to date for you."
        actions={
          manage ? (
            <>
              <Button
                variant="secondary"
                icon={<MessageSquarePlus className="size-4" />}
                onClick={() => setSuggesting(true)}
              >
                Suggest a qualification
              </Button>
              <Button icon={<Plus className="size-4" />} onClick={() => setBrowsing(true)}>
                Add from catalogue
              </Button>
            </>
          ) : null
        }
      />

      {q.data?.suggested.length && manage ? (
        <Card className="mb-4">
          <CardHeader
            title="On your accreditation letter"
            subtitle="These SAQA IDs are in your centres' scope and in the catalogue."
          />
          <ul className="grid gap-2">
            {q.data.suggested.map((s) => (
              <li
                key={s.id}
                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3"
              >
                <span className="flex min-w-0 items-center gap-2">
                  <Sparkles className="size-4 shrink-0 text-primary-700" aria-hidden />
                  <span className="truncate font-medium">{s.title}</span>
                  <span className="text-small text-muted">SAQA {s.saqa_id}</span>
                </span>
                <Button size="sm" onClick={() => setAdding(s)}>
                  Add
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {attention.length ? (
        <div className="mb-4">
          <Alert tone="warning">
            {attention.length === 1
              ? `${attention[0]!.qualification.title} ${attention[0]!.qualification.enrolment === 'closed' ? 'is closed to new learners' : `closes to new learners on ${attention[0]!.qualification.last_enrolment_on}`}.`
              : `${attention.length} qualifications are closing or closed to new learners.`}{' '}
            Learners already enrolled may finish by the last date for achievement.
          </Alert>
        </div>
      ) : null}

      <Card>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={q.data?.data ?? []}
            rowKey={(p) => p.id}
            empty={
              <EmptyState icon={<BookOpen className="size-5" />} title="No programmes yet">
                Add the qualifications you are accredited for from the catalogue.
              </EmptyState>
            }
            columns={[
              {
                key: 'title',
                header: 'Qualification',
                cell: (p) => (
                  <div className="min-w-0">
                    <div className="truncate font-medium text-ink">{p.qualification.title}</div>
                    <div className="text-small text-muted">
                      {p.qualification.type_label} · NQF {p.qualification.nqf_level} · {p.qualification.credits} credits
                    </div>
                  </div>
                ),
              },
              {
                key: 'saqa',
                header: 'SAQA ID',
                cell: (p) => <span className="tabular-nums">{p.qualification.saqa_id ?? '—'}</span>,
              },
              {
                key: 'centres',
                header: 'Centres',
                cell: (p) =>
                  p.centres.length ? (
                    p.centres.map((c) => c.name).join(', ')
                  ) : (
                    <span className="text-muted">None yet</span>
                  ),
              },
              { key: 'price', header: 'Price', cell: (p) => (p.price_cents !== null ? money(p.price_cents) : '—') },
              { key: 'enrolment', header: 'Enrolment', cell: (p) => <EnrolmentBadge q={p.qualification} /> },
              ...(materials
                ? [
                    {
                      key: 'materials',
                      header: <span className="sr-only">Materials</span>,
                      cell: (p: ProgrammeRow) => (
                        <span className="flex gap-3">
                          <Link
                            to="/programmes/$id/materials"
                            params={{ id: p.qualification.id }}
                            className="text-small font-medium text-primary-700 hover:underline"
                          >
                            Materials<span className="sr-only"> for {p.qualification.title}</span>
                          </Link>
                          <Link
                            to="/programmes/$id/tasks"
                            params={{ id: p.qualification.id }}
                            className="text-small font-medium text-primary-700 hover:underline"
                          >
                            Tasks<span className="sr-only"> for {p.qualification.title}</span>
                          </Link>
                          <Link
                            to="/programmes/$id/logbook"
                            params={{ id: p.qualification.id }}
                            className="text-small font-medium text-primary-700 hover:underline"
                          >
                            Logbook<span className="sr-only"> for {p.qualification.title}</span>
                          </Link>
                        </span>
                      ),
                    },
                  ]
                : []),
            ]}
          />
        )}
      </Card>

      {browsing ? <Browse onClose={() => setBrowsing(false)} onPick={(s) => setAdding(s)} /> : null}
      {adding ? <AddProgramme q={adding} onClose={() => setAdding(null)} /> : null}
      {suggesting ? <Suggest onClose={() => setSuggesting(false)} /> : null}
    </>
  );
}

function Browse({ onClose, onPick }: { onClose: () => void; onPick: (q: QualificationSummary) => void }) {
  const [search, setSearch] = useState('');
  const [type, setType] = useState<'' | 'occupational' | 'legacy'>('');
  const term = useDeferredValue(search);
  const q = useQuery({
    queryKey: ['catalogue', term, type],
    placeholderData: keepPreviousData,
    queryFn: () =>
      call<{ data: QualificationSummary[] }>('GET', '/catalogue', undefined, {
        q: term || undefined,
        type: type || undefined,
      }).then((r) => r.data),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Qualification catalogue"
      description="Search by title, SAQA ID or curriculum code."
    >
      <div className="grid gap-3">
        <Field label="Search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <div className="flex gap-2" role="group" aria-label="Type">
          {(
            [
              ['', 'All'],
              ['occupational', 'Occupational'],
              ['legacy', 'Legacy'],
            ] as const
          ).map(([k, label]) => (
            <Button
              key={k}
              type="button"
              size="sm"
              variant={type === k ? 'primary' : 'secondary'}
              aria-pressed={type === k}
              onClick={() => setType(k)}
            >
              {label}
            </Button>
          ))}
        </div>
        <ul className="grid max-h-96 gap-2 overflow-auto">
          {q.data?.map((x) => (
            <li key={x.id} className="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
              <div className="min-w-0">
                <p className="truncate font-medium">{x.title}</p>
                <p className="text-small text-muted">
                  SAQA {x.saqa_id ?? '—'} · NQF {x.nqf_level} · {x.credits} credits
                </p>
                <div className="mt-1">
                  <EnrolmentBadge q={x} />
                </div>
              </div>
              {x.offered ? (
                <Badge tone="success">Offered</Badge>
              ) : (
                <Button
                  size="sm"
                  disabled={x.enrolment === 'ended'}
                  onClick={() => {
                    onClose();
                    onPick(x);
                  }}
                >
                  Add
                </Button>
              )}
            </li>
          ))}
          {q.data && !q.data.length ? (
            <li className="text-small text-muted">Nothing found. Suggest it to the catalogue team.</li>
          ) : null}
        </ul>
      </div>
    </Dialog>
  );
}

function AddProgramme({ q, onClose }: { q: QualificationSummary; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [modes, setModes] = useState<string[]>(['contact']);
  const [price, setPrice] = useState('');
  const detail = useQuery({
    queryKey: ['catalogue', 'detail', q.id],
    queryFn: () => call<{ data: QualificationDetail }>('GET', `/catalogue/${q.id}`).then((r) => r.data),
  });
  const add = useMutation({
    mutationFn: () =>
      call('POST', '/programmes', {
        qualification_id: q.id,
        modes,
        price_cents: price === '' ? null : Math.round(Number(price) * 100),
      }),
    onSuccess: () => {
      toast(`${q.title} added`);
      void qc.invalidateQueries({ queryKey: ['programmes'] });
      onClose();
    },
  });
  const kinds = (detail.data?.modules ?? []).reduce<Record<string, number>>(
    (acc, m) => ({ ...acc, [m.kind]: (acc[m.kind] ?? 0) + 1 }),
    {},
  );

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Offer ${q.title}`}
      description="Centres whose accreditation letter lists this SAQA ID are linked for you."
    >
      <div className="grid gap-3">
        <EnrolmentBadge q={q} />
        {q.enrolment === 'closed' ? (
          <Alert tone="warning">
            Closed to new learners since {q.last_enrolment_on}. Add it only to finish learners already enrolled.
          </Alert>
        ) : null}
        {detail.data ? (
          <p className="text-small text-muted">
            {Object.entries(kinds)
              .map(([k, n]) => `${n} ${k === 'US' ? 'unit standards' : k}`)
              .join(' · ')}
            {detail.data.successors.length
              ? ` · replaced by ${detail.data.successors.map((s) => s.title).join('; ')}`
              : ''}
          </p>
        ) : null}
        <fieldset className="grid gap-2">
          <legend className="text-small font-medium">How you deliver it</legend>
          <div className="flex flex-wrap gap-3">
            {MODES.map((m) => (
              <label key={m} className="flex items-center gap-1.5 text-small capitalize">
                <input
                  type="checkbox"
                  checked={modes.includes(m)}
                  onChange={(e) => setModes(e.target.checked ? [...modes, m] : modes.filter((x) => x !== m))}
                />{' '}
                {m}
              </label>
            ))}
          </div>
        </fieldset>
        <Field
          label="Price per learner (optional, rand)"
          inputMode="decimal"
          value={price}
          onChange={(e) => setPrice(e.target.value)}
        />
        {add.error ? <Alert tone="danger">{errorMessage(add.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={add.isPending} onClick={() => add.mutate()}>
            Add programme
          </Button>
        </div>
      </div>
    </Dialog>
  );
}

function Suggest({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState({ saqa_id: '', title: '', details: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const send = useMutation({
    mutationFn: () => call('POST', '/catalogue-suggestions', { kind: 'new', ...form, saqa_id: form.saqa_id || null }),
    onSuccess: () => {
      toast('Sent to the catalogue team');
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Suggest a qualification"
      description="Missing from the catalogue? The catalogue team checks it and replies by email."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          send.mutate();
        }}
      >
        <Field
          label="SAQA ID"
          inputMode="numeric"
          value={form.saqa_id}
          onChange={(e) => setForm({ ...form, saqa_id: e.target.value })}
          error={errors.saqa_id?.[0]}
        />
        <Field
          label="Title"
          required
          value={form.title}
          onChange={(e) => setForm({ ...form, title: e.target.value })}
          error={errors.title?.[0]}
        />
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Details</span>
          <textarea
            required
            minLength={10}
            className="min-h-24 rounded-lg border border-line bg-surface p-2"
            placeholder="Why you need it, and where the curriculum can be found"
            value={form.details}
            onChange={(e) => setForm({ ...form, details: e.target.value })}
          />
          {errors.details ? <span className="text-danger">{errors.details[0]}</span> : null}
        </label>
        {send.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(send.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={send.isPending}>
            Send
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
