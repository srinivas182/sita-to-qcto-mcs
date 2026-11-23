import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Plus, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
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
  Select,
  Skeleton,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { shortDate } from '@/lib/money';
import { useCan } from '@/lib/session';
import { type Chain, type Entry, type ModuleHours, type Signoff, duration, entryState, signoffState } from './types';

interface PlacementRow {
  id: string;
  enrolment_id: string;
  learner: string | null;
  site: string | null;
  mentor: string | null;
  starts_on: string;
  ends_on: string | null;
}

interface Options {
  learners: { enrolment_id: string; name: string | null }[];
  sites: { id: string; name: string; employer: string | null }[];
  mentors: { id: string; name: string; site_ids: string[] }[];
}

interface LearnerRow {
  user_id: string;
  enrolment_id: string;
  signoffs: Signoff[];
  name: string | null;
  hours: ModuleHours[];
  last_entry: string | null;
  unsigned_weeks: number;
  chain: Chain;
}

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Johannesburg' });

/** Where each learner is placed, their logged hours per workplace module, and whether each logbook is intact. */
export function CohortLogbookPage() {
  const { id } = useParams({ from: '/app/cohorts/$id/logbook' });
  const can = useCan();
  const manage = can('cohorts.manage');
  const [placing, setPlacing] = useState(false);
  const [viewing, setViewing] = useState<LearnerRow | null>(null);
  const [checking, setChecking] = useState<{ learner: LearnerRow; signoff: Signoff; code: string } | null>(null);
  const assessor = can('assessment.conduct');
  const placements = useQuery({
    queryKey: ['learner-placements', id],
    queryFn: () => call<{ data: PlacementRow[]; options: Options }>('GET', `/cohorts/${id}/learner-placements`),
  });
  const summary = useQuery({
    queryKey: ['cohort-logbook', id],
    queryFn: () => call<{ data: LearnerRow[] }>('GET', `/cohorts/${id}/logbook`).then((r) => r.data),
  });

  return (
    <>
      <Link
        to="/cohorts/$id"
        params={{ id }}
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Cohort
      </Link>
      <PageHeader
        title="Workplace logbook"
        description="Place learners with a mentor, then follow the hours they log on site. Each logbook is chained, so a changed entry shows."
      />

      <Card className="mb-4">
        <CardHeader
          title="Placements"
          action={
            manage && placements.data ? (
              <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setPlacing(true)}>
                Place a learner
              </Button>
            ) : null
          }
        />
        {placements.isPending ? (
          <Skeleton className="h-24" />
        ) : placements.isError ? (
          <Alert tone="danger">{errorMessage(placements.error)}</Alert>
        ) : placements.data.data.length === 0 ? (
          <EmptyState title="No one placed yet">
            {placements.data.options.sites.length === 0
              ? 'Add workplaces to the cohort first.'
              : 'Place each learner at one of the cohort’s workplaces.'}
          </EmptyState>
        ) : (
          <ul className="divide-y divide-line">
            {placements.data.data.map((p) => (
              <PlacementItem key={p.id} cohortId={id} p={p} manage={manage} />
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="Hours logged" />
        {summary.isPending ? (
          <Skeleton className="h-24" />
        ) : summary.isError ? (
          <Alert tone="danger">{errorMessage(summary.error)}</Alert>
        ) : summary.data.length === 0 ? (
          <EmptyState title="No learners">Learners appear here once enrolled.</EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-small">
              <caption className="sr-only">Hours logged per learner and workplace module</caption>
              <thead>
                <tr className="border-b border-line text-left text-muted">
                  <th scope="col" className="py-2 pr-3 font-medium">
                    Learner
                  </th>
                  {(summary.data[0]?.hours ?? []).map((h) => (
                    <th key={h.module_id} scope="col" className="px-2 py-2 font-medium">
                      {h.code}
                    </th>
                  ))}
                  <th scope="col" className="px-2 py-2 font-medium">
                    Last entry
                  </th>
                  <th scope="col" className="px-2 py-2 font-medium">
                    Unsigned weeks
                  </th>
                  <th scope="col" className="px-2 py-2 font-medium">
                    Logbook
                  </th>
                </tr>
              </thead>
              <tbody>
                {summary.data.map((l) => (
                  <tr key={l.user_id} className="border-b border-line">
                    <th scope="row" className="py-2 pr-3 text-left font-medium">
                      <button type="button" className="text-primary-700 hover:underline" onClick={() => setViewing(l)}>
                        {l.name}
                      </button>
                    </th>
                    {l.hours.map((h) => (
                      <td key={h.module_id} className="px-2 py-2 tabular-nums">
                        {h.hours} h{h.hours_needed ? <span className="text-muted"> of {h.hours_needed}</span> : null}
                        {h.signed_hours > 0 ? (
                          <span className="block text-muted">{h.signed_hours} h signed</span>
                        ) : null}
                        <SignoffCell
                          signoff={l.signoffs.find((x) => x.module_id === h.module_id)}
                          canCheck={assessor}
                          label={`${h.code} for ${l.name ?? 'the learner'}`}
                          onCheck={(so) => setChecking({ learner: l, signoff: so, code: h.code })}
                        />
                      </td>
                    ))}
                    <td className="px-2 py-2">
                      {l.last_entry ? shortDate(l.last_entry) : <span className="text-muted">None</span>}
                    </td>
                    <td className="px-2 py-2 tabular-nums">
                      {l.unsigned_weeks > 0 ? (
                        <Badge tone="warning">{l.unsigned_weeks}</Badge>
                      ) : (
                        <span className="text-muted">0</span>
                      )}
                    </td>
                    <td className="px-2 py-2">
                      <ChainBadge chain={l.chain} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {placing && placements.data ? (
        <PlaceDialog cohortId={id} options={placements.data.options} onClose={() => setPlacing(false)} />
      ) : null}
      {checking ? <CheckDialog cohortId={id} {...checking} onClose={() => setChecking(null)} /> : null}
      {viewing ? <EntriesDialog cohortId={id} learner={viewing} onClose={() => setViewing(null)} /> : null}
    </>
  );
}

function SignoffCell({
  signoff,
  canCheck,
  label,
  onCheck,
}: {
  signoff: Signoff | undefined;
  canCheck: boolean;
  label: string;
  onCheck: (s: Signoff) => void;
}) {
  if (!signoff) return null;
  return canCheck && signoff.status === 'signed' ? (
    <Button size="sm" variant="secondary" className="mt-1" onClick={() => onCheck(signoff)}>
      Check sign-off<span className="sr-only"> {label}</span>
    </Button>
  ) : (
    <span className="mt-1 block">
      <Badge tone={signoffState[signoff.status].tone}>{signoffState[signoff.status].label}</Badge>
    </span>
  );
}

function ChainBadge({ chain }: { chain: Chain }) {
  if (chain.entries === 0) return <span className="text-muted">No entries</span>;
  if (chain.ok && chain.signatures_ok === false)
    return (
      <Badge tone="danger">
        <ShieldAlert className="size-3.5" aria-hidden /> Changed after signing
      </Badge>
    );
  return chain.ok ? (
    <Badge tone="success">
      <ShieldCheck className="size-3.5" aria-hidden /> Intact
    </Badge>
  ) : (
    <Badge tone="danger">
      <ShieldAlert className="size-3.5" aria-hidden /> Changed at entry {chain.broken_at}
    </Badge>
  );
}

function PlacementItem({ cohortId, p, manage }: { cohortId: string; p: PlacementRow; manage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const end = useMutation({
    mutationFn: () => call('POST', `/learner-placements/${p.id}/end`, { ends_on: today() }),
    onSuccess: () => {
      toast('Placement ended today');
      void qc.invalidateQueries({ queryKey: ['learner-placements', cohortId] });
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  const ended = p.ends_on !== null && p.ends_on < today();
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2.5">
      <div>
        <p className="font-medium text-ink">{p.learner}</p>
        <p className="text-small text-muted">
          {p.site}
          {p.mentor ? ` · mentor ${p.mentor}` : ' · no mentor yet'} · from {shortDate(p.starts_on)}
          {p.ends_on ? ` to ${shortDate(p.ends_on)}` : ''}
        </p>
      </div>
      {ended ? (
        <Badge>Ended</Badge>
      ) : manage && p.ends_on === null ? (
        <Button size="sm" variant="ghost" loading={end.isPending} onClick={() => end.mutate()}>
          End today<span className="sr-only"> for {p.learner}</span>
        </Button>
      ) : null}
    </li>
  );
}

function PlaceDialog({ cohortId, options, onClose }: { cohortId: string; options: Options; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    enrolment_id: options.learners[0]?.enrolment_id ?? '',
    site_id: options.sites[0]?.id ?? '',
    mentor_id: '',
    starts_on: today(),
    ends_on: '',
  });
  const [error, setError] = useState<string | null>(null);
  const mentors = options.mentors.filter((m) => m.site_ids.includes(form.site_id));
  const save = useMutation({
    mutationFn: () =>
      call('POST', `/cohorts/${cohortId}/learner-placements`, {
        ...form,
        mentor_id: form.mentor_id || null,
        ends_on: form.ends_on || null,
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['learner-placements', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Place a learner"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Place
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Select
          label="Learner"
          value={form.enrolment_id}
          onChange={(e) => setForm({ ...form, enrolment_id: e.target.value })}
        >
          {options.learners.map((l) => (
            <option key={l.enrolment_id} value={l.enrolment_id}>
              {l.name}
            </option>
          ))}
        </Select>
        <Select
          label="Workplace"
          value={form.site_id}
          onChange={(e) => setForm({ ...form, site_id: e.target.value, mentor_id: '' })}
        >
          {options.sites.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
              {s.employer ? ` (${s.employer})` : ''}
            </option>
          ))}
        </Select>
        <Select label="Mentor" value={form.mentor_id} onChange={(e) => setForm({ ...form, mentor_id: e.target.value })}>
          <option value="">Choose later</option>
          {mentors.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </Select>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Starts"
            type="date"
            value={form.starts_on}
            onChange={(e) => setForm({ ...form, starts_on: e.target.value })}
          />
          <Field
            label="Ends (optional)"
            type="date"
            value={form.ends_on}
            onChange={(e) => setForm({ ...form, ends_on: e.target.value })}
          />
        </div>
      </div>
    </Dialog>
  );
}

function EntriesDialog({ cohortId, learner, onClose }: { cohortId: string; learner: LearnerRow; onClose: () => void }) {
  const q = useQuery({
    queryKey: ['cohort-logbook', cohortId, learner.user_id],
    queryFn: () =>
      call<{ data: { entries: Entry[]; chain: Chain } }>('GET', `/cohorts/${cohortId}/logbook/${learner.user_id}`).then(
        (r) => r.data,
      ),
  });
  const codes = new Map(learner.hours.map((h) => [h.module_id, h.code]));
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={`${learner.name ?? 'Learner'}’s logbook`}>
      {q.isPending ? (
        <Skeleton className="h-32" />
      ) : q.isError ? (
        <Alert tone="danger">{errorMessage(q.error)}</Alert>
      ) : q.data.entries.length === 0 ? (
        <EmptyState title="No entries yet">Entries appear when the learner logs work on site.</EmptyState>
      ) : (
        <>
          {!q.data.chain.ok ? (
            <Alert tone="danger">
              Entry {q.data.chain.broken_at} was changed after it was written. Look into it before signing off.
            </Alert>
          ) : null}
          <ul className="mt-2 divide-y divide-line">
            {q.data.entries.map((e) => (
              <li key={e.id} className="py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium text-ink">
                    {shortDate(e.work_date)} · {codes.get(e.module_id) ?? ''} · {duration(e.minutes)}
                  </p>
                  <Badge tone={entryState[e.status].tone}>{entryState[e.status].label}</Badge>
                </div>
                {e.note ? <p className="text-small text-ink">{e.note}</p> : null}
                <p className="text-small text-muted">
                  {e.distance_m !== null
                    ? e.location_ok
                      ? `On site (${e.distance_m} m)`
                      : `${e.distance_m} m from the site`
                    : 'No location'}
                  {e.offline ? ' · written offline' : ''}
                  {e.replaces_id ? ' · a correction' : ''}
                </p>
              </li>
            ))}
          </ul>
        </>
      )}
    </Dialog>
  );
}

function CheckDialog({
  cohortId,
  learner,
  signoff,
  code,
  onClose,
}: {
  cohortId: string;
  learner: LearnerRow;
  signoff: Signoff;
  code: string;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const [comment, setComment] = useState('');
  const [error, setError] = useState<string | null>(null);
  const check = useMutation({
    mutationFn: (outcome: 'checked' | 'queried') =>
      call('POST', `/logbook-signoffs/${signoff.id}/check`, { outcome, comment: comment || null }),
    onSuccess: (_, outcome) => {
      toast(outcome === 'checked' ? 'Logbook checked' : 'Sent back to the mentor');
      void qc.invalidateQueries({ queryKey: ['cohort-logbook', cohortId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const intact = learner.chain.ok && learner.chain.signatures_ok !== false;
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Check ${code} for ${learner.name ?? 'the learner'}`}
      description={`The mentor signed off ${signoff.hours} hours on ${shortDate(signoff.signed_at)}.`}
      footer={
        <>
          <Button
            variant="secondary"
            loading={check.isPending && check.variables === 'queried'}
            onClick={() => check.mutate('queried')}
          >
            Query
          </Button>
          <Button
            disabled={!intact}
            loading={check.isPending && check.variables === 'checked'}
            onClick={() => check.mutate('checked')}
          >
            Confirm
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {intact ? (
          <Alert tone="success">The logbook is intact: no entry changed after it was written or signed.</Alert>
        ) : (
          <Alert tone="danger">The logbook changed after it was written or signed. Query it.</Alert>
        )}
        {signoff.mentor_comment ? <p className="text-small text-ink">Mentor: {signoff.mentor_comment}</p> : null}
        <div className="grid gap-1.5">
          <label htmlFor="check-comment" className="text-small font-medium text-ink">
            Comment (needed to query)
          </label>
          <textarea
            id="check-comment"
            className="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-body"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
        </div>
      </div>
    </Dialog>
  );
}
