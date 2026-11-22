import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { BookOpen, Plus } from 'lucide-react';
import { useDeferredValue, useState } from 'react';
import {
  Alert,
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
  cx,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { EnrolmentBadge } from './EnrolmentBadge';
import type { QualificationSummary } from './types';

interface Suggestion {
  id: string;
  kind: 'new' | 'correction';
  title: string;
  saqa_id: string | null;
  details: string;
  status: string;
  provider: string | null;
  created_at: string;
}

const tabs = [
  ['all', 'All'],
  ['published', 'Published'],
  ['draft', 'Drafts'],
  ['closing', 'Closing soon'],
  ['suggestions', 'Suggestions'],
] as const;

/** The shared qualification catalogue every provider adopts from. */
export function CatalogueAdminPage() {
  const navigate = useNavigate();
  const [tab, setTab] = useState<(typeof tabs)[number][0]>('all');
  const [search, setSearch] = useState('');
  const q = useDeferredValue(search);
  const [adding, setAdding] = useState(false);
  const list = useQuery({
    queryKey: ['admin', 'catalogue', tab, q],
    enabled: tab !== 'suggestions',
    placeholderData: keepPreviousData,
    queryFn: () =>
      call<{ data: QualificationSummary[]; counts: Record<string, number>; types: Record<string, string> }>(
        'GET',
        '/admin/qualifications',
        undefined,
        {
          status: tab === 'published' || tab === 'draft' ? tab : 'all',
          closing: tab === 'closing' ? 1 : undefined,
          q: q || undefined,
        },
      ),
  });

  return (
    <>
      <PageHeader
        eyebrow="Platform console"
        title="Qualification catalogue"
        description="One shared catalogue. Providers add qualifications from it, never retype them. Enrolment closes on the gazetted date, and the replacements are offered."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
            Add qualification
          </Button>
        }
      />
      <Card>
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <div className="flex gap-1 overflow-x-auto rounded-lg bg-line-soft p-1">
            {tabs.map(([key, label]) => {
              const count = key === 'all' ? undefined : list.data?.counts[key];
              return (
                <button
                  key={key}
                  type="button"
                  aria-pressed={tab === key}
                  onClick={() => setTab(key)}
                  className={cx(
                    'shrink-0 rounded-md px-3 py-1 text-small',
                    tab === key ? 'bg-surface text-ink shadow-sm' : 'text-muted',
                  )}
                >
                  {label}
                  {count ? <span className="ml-1.5 tabular-nums text-faint">{count}</span> : null}
                </button>
              );
            })}
          </div>
          {tab !== 'suggestions' ? (
            <div className="ml-auto w-full sm:w-72">
              <Field
                label="Search"
                placeholder="Title, SAQA ID or curriculum code"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          ) : null}
        </div>
        {tab === 'suggestions' ? (
          <Suggestions />
        ) : list.isPending ? (
          <Skeleton className="h-40" />
        ) : (
          <Table
            rows={list.data?.data ?? []}
            rowKey={(r) => r.id}
            onRowClick={(r) => void navigate({ to: '/catalogue/$id', params: { id: r.id } })}
            empty={<EmptyState icon={<BookOpen className="size-5" />} title="No qualifications here" />}
            columns={[
              {
                key: 'title',
                header: 'Qualification',
                cell: (r) => (
                  <div className="min-w-0">
                    <div className="truncate font-medium text-ink">{r.title}</div>
                    <div className="text-small text-muted">
                      {r.type_label} · NQF {r.nqf_level} · {r.credits} credits
                    </div>
                  </div>
                ),
              },
              { key: 'saqa', header: 'SAQA ID', cell: (r) => <span className="tabular-nums">{r.saqa_id ?? '—'}</span> },
              {
                key: 'modules',
                header: 'Modules',
                cell: (r) => <span className="tabular-nums">{r.modules ?? 0}</span>,
              },
              {
                key: 'enrolment',
                header: 'Enrolment',
                cell: (r) => (r.status === 'published' ? <EnrolmentBadge q={r} /> : <StatusBadge status={r.status} />),
              },
            ]}
          />
        )}
      </Card>
      {adding ? <AddQualification types={list.data?.types ?? {}} onClose={() => setAdding(false)} /> : null}
    </>
  );
}

function AddQualification({ types, onClose }: { types: Record<string, string>; onClose: () => void }) {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    type: 'occupational_certificate',
    saqa_id: '',
    title: '',
    nqf_level: '',
    credits: '',
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const save = useMutation({
    mutationFn: () =>
      call<{ data: { id: string } }>('POST', '/admin/qualifications', {
        ...form,
        saqa_id: form.saqa_id || null,
        nqf_level: Number(form.nqf_level),
        credits: Number(form.credits),
      }),
    onSuccess: (r) => void navigate({ to: '/catalogue/$id', params: { id: r.data.id } }),
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
      title="Add a qualification"
      description="Start from the SAQA record. Add the modules next, then publish."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Select label="Type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
          {Object.entries(types).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </Select>
        {f('saqa_id', 'SAQA ID', { inputMode: 'numeric' })}
        {f('title', 'Title', { required: true })}
        <div className="grid grid-cols-2 gap-3">
          {f('nqf_level', 'NQF level', { inputMode: 'numeric', required: true })}
          {f('credits', 'Credits', { inputMode: 'numeric', required: true })}
        </div>
        {save.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(save.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Create draft
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function Suggestions() {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({
    queryKey: ['admin', 'catalogue-suggestions'],
    queryFn: () => call<{ data: Suggestion[] }>('GET', '/admin/catalogue-suggestions').then((r) => r.data),
  });
  const [answering, setAnswering] = useState<{ s: Suggestion; accept: boolean } | null>(null);
  const [response, setResponse] = useState('');
  const decide = useMutation({
    mutationFn: () =>
      call('POST', `/admin/catalogue-suggestions/${answering!.s.id}/decide`, { accept: answering!.accept, response }),
    onSuccess: () => {
      toast('Answer sent');
      setAnswering(null);
      setResponse('');
      void qc.invalidateQueries({ queryKey: ['admin', 'catalogue-suggestions'] });
    },
  });

  if (q.isPending) return <Skeleton className="h-32" />;
  return (
    <>
      {q.data?.length ? (
        <ul className="grid gap-3">
          {q.data.map((s) => (
            <li key={s.id} className="rounded-lg border border-line p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-medium text-ink">
                    {s.kind === 'new' ? 'Add: ' : 'Correct: '}
                    {s.title} {s.saqa_id ? <span className="text-muted">(SAQA {s.saqa_id})</span> : null}
                  </p>
                  <p className="text-small text-muted">From {s.provider ?? 'a provider'}</p>
                  <p className="mt-2 whitespace-pre-line text-small">{s.details}</p>
                </div>
                <div className="flex gap-2">
                  <Button variant="secondary" size="sm" onClick={() => setAnswering({ s, accept: false })}>
                    Decline
                  </Button>
                  <Button size="sm" onClick={() => setAnswering({ s, accept: true })}>
                    Accept
                  </Button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={<BookOpen className="size-5" />} title="No open suggestions" />
      )}
      {answering ? (
        <Dialog
          open
          onOpenChange={(o) => !o && setAnswering(null)}
          title={answering.accept ? 'Accept suggestion' : 'Decline suggestion'}
          description="The provider gets your answer by email."
        >
          <form
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              decide.mutate();
            }}
          >
            <label className="grid gap-1.5 text-small">
              <span className="font-medium">Answer</span>
              <textarea
                required
                minLength={5}
                className="min-h-24 rounded-lg border border-line bg-surface p-2"
                value={response}
                onChange={(e) => setResponse(e.target.value)}
              />
            </label>
            {decide.error ? <Alert tone="danger">{errorMessage(decide.error)}</Alert> : null}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setAnswering(null)}>
                Cancel
              </Button>
              <Button type="submit" loading={decide.isPending}>
                Send
              </Button>
            </div>
          </form>
        </Dialog>
      ) : null}
    </>
  );
}
