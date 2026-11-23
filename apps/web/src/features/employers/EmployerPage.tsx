import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, Pencil, Plus, UserRound, Warehouse } from 'lucide-react';
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
  Table,
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { uploadFile } from '@/lib/upload';
import { EmployerDialog } from './EmployersPage';
import { approvalState, clean, PROVINCES, shortDate, type EmployerDetail, type Mentor, type Site } from './types';

type Open =
  | { kind: 'employer' }
  | { kind: 'site'; site: Site | null }
  | { kind: 'mentor'; mentor: Mentor | null }
  | { kind: 'member' }
  | null;

/** One host employer: workplaces, approvals, mentors, capacity and the people who act for it. */
export function EmployerPage() {
  const { id } = useParams({ from: '/app/employers/$id' });
  const can = useCan();
  const manage = can('employers.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [open, setOpen] = useState<Open>(null);
  const q = useQuery({
    queryKey: ['employers', id],
    queryFn: () => call<{ data: EmployerDetail; setas: string[]; bbbee: string[] }>('GET', `/employers/${id}`),
  });
  const unlink = useMutation({
    mutationFn: (userId: string) => call('DELETE', `/employers/${id}/members/${userId}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['employers', id] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const e = q.data.data;
  const lists = { data: q.data };
  const siteName = (sid: string) => e.sites.find((s) => s.id === sid)?.name ?? '—';

  return (
    <>
      <Link to="/employers" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Employers
      </Link>
      <PageHeader
        eyebrow="Host employer"
        title={e.legal_name}
        description={[
          e.trading_name,
          e.registration_number,
          e.sdl_number,
          e.seta,
          e.bbbee_level ? `B-BBEE ${e.bbbee_level}` : null,
        ]
          .filter(Boolean)
          .join(' · ')}
        actions={
          manage ? (
            <Button
              variant="secondary"
              icon={<Pencil className="size-4" />}
              onClick={() => setOpen({ kind: 'employer' })}
            >
              Change details
            </Button>
          ) : null
        }
      />

      <div className="grid gap-4">
        <Card>
          <CardHeader
            title="Workplaces"
            subtitle="Each site needs its own SETA workplace approval for the qualifications it may host."
            action={
              manage ? (
                <Button
                  size="sm"
                  icon={<Plus className="size-4" />}
                  onClick={() => setOpen({ kind: 'site', site: null })}
                >
                  Add workplace
                </Button>
              ) : null
            }
          />
          <Table
            rows={e.sites}
            rowKey={(s) => s.id}
            empty={
              <EmptyState icon={<Warehouse className="size-5" />} title="No workplaces yet">
                Add each site where learners will work, with its approval letter.
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Workplace',
                cell: (s) => (
                  <div className="min-w-0">
                    <div className="font-medium text-ink">{s.name}</div>
                    <div className="text-small text-muted">
                      {[s.city, s.province].filter(Boolean).join(', ') || '—'}
                    </div>
                  </div>
                ),
              },
              {
                key: 'approval',
                header: 'Approval',
                cell: (s) => (
                  <div className="grid gap-1">
                    <Badge tone={approvalState[s.approval_state].tone} dot>
                      {approvalState[s.approval_state].label}
                    </Badge>
                    {s.approval_number ? (
                      <span className="text-small text-muted">
                        {s.approval_body} {s.approval_number} · until {shortDate(s.approval_expires_on)}
                      </span>
                    ) : null}
                  </div>
                ),
              },
              {
                key: 'quals',
                header: 'Approved for',
                cell: (s) => (
                  <span className="text-small">{s.qualifications.map((x) => x.title ?? x.id).join(', ') || '—'}</span>
                ),
              },
              {
                key: 'actions',
                header: <span className="sr-only">Actions</span>,
                cell: (s) =>
                  manage ? (
                    <div className="flex justify-end">
                      <Button size="sm" variant="secondary" onClick={() => setOpen({ kind: 'site', site: s })}>
                        Change<span className="sr-only"> {s.name}</span>
                      </Button>
                    </div>
                  ) : null,
              },
            ]}
          />
        </Card>

        <Card>
          <CardHeader
            title="Capacity"
            subtitle="Learners each workplace can take at once: active mentors at the site × learners per mentor. Nothing is counted while an approval is out of date."
          />
          {e.capacity.length ? (
            <Table
              rows={e.capacity}
              rowKey={(c) => `${c.site_id}-${c.qualification_id}`}
              columns={[
                { key: 'site', header: 'Workplace', cell: (c) => siteName(c.site_id) },
                { key: 'qual', header: 'Qualification', cell: (c) => <span className="text-small">{c.title}</span> },
                { key: 'mentors', header: 'Mentors', cell: (c) => <span className="tabular-nums">{c.mentors}</span> },
                { key: 'ratio', header: 'Per mentor', cell: (c) => <span className="tabular-nums">{c.ratio}</span> },
                {
                  key: 'capacity',
                  header: 'Learners',
                  cell: (c) => <span className="font-medium tabular-nums">{c.capacity}</span>,
                },
                {
                  key: 'placed',
                  header: 'In cohorts now',
                  cell: (c) => (
                    <span className={c.placed > c.capacity ? 'font-medium text-danger tabular-nums' : 'tabular-nums'}>
                      {c.placed}
                    </span>
                  ),
                },
              ]}
            />
          ) : (
            <p className="text-small text-muted">Add a workplace approved for a qualification, and its mentors.</p>
          )}
        </Card>

        <Card>
          <CardHeader
            title="Workplace mentors"
            subtitle="The people at the employer who guide learners day to day and sign off their logbooks."
            action={
              manage && e.sites.length ? (
                <Button
                  size="sm"
                  icon={<Plus className="size-4" />}
                  onClick={() => setOpen({ kind: 'mentor', mentor: null })}
                >
                  Add mentor
                </Button>
              ) : null
            }
          />
          <Table
            rows={e.mentors}
            rowKey={(m) => m.id}
            empty={
              <EmptyState icon={<UserRound className="size-5" />} title="No mentors yet">
                {e.sites.length ? 'Add the mentors at each workplace.' : 'Add a workplace first.'}
              </EmptyState>
            }
            columns={[
              {
                key: 'name',
                header: 'Mentor',
                cell: (m) => (
                  <div className="min-w-0">
                    <div className="font-medium text-ink">{m.name}</div>
                    <div className="text-small text-muted">{m.designation}</div>
                  </div>
                ),
              },
              {
                key: 'exp',
                header: 'Experience',
                cell: (m) => (
                  <span className="text-small">
                    {m.years_experience} years
                    {m.highest_qualification ? ` · ${m.highest_qualification}` : ''}
                    {m.qualification_nqf_level ? ` (NQF ${m.qualification_nqf_level})` : ''}
                  </span>
                ),
              },
              {
                key: 'sites',
                header: 'Workplaces',
                cell: (m) => <span className="text-small">{m.site_ids.map(siteName).join(', ')}</span>,
              },
              {
                key: 'active',
                header: 'Status',
                cell: (m) => (
                  <Badge tone={m.active ? 'success' : 'neutral'} dot>
                    {m.active ? 'Active' : 'Inactive'}
                  </Badge>
                ),
              },
              {
                key: 'actions',
                header: <span className="sr-only">Actions</span>,
                cell: (m) =>
                  manage ? (
                    <div className="flex justify-end">
                      <Button size="sm" variant="secondary" onClick={() => setOpen({ kind: 'mentor', mentor: m })}>
                        Change<span className="sr-only"> {m.name}</span>
                      </Button>
                    </div>
                  ) : null,
              },
            ]}
          />
          {e.workplace_criteria.length ? (
            <div className="mt-4 rounded-lg bg-line-soft p-3">
              <h3 className="mb-2 text-small font-medium text-ink">What the curriculum asks of the workplace</h3>
              <ul className="grid gap-2 text-small">
                {e.workplace_criteria.map((c) => (
                  <li key={`${c.qualification}-${c.code}`}>
                    <span className="font-medium">{c.code}</span> {c.title}:{' '}
                    <span className="text-muted">{c.criteria}</span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </Card>

        <Card>
          <CardHeader
            title="People who act for the employer"
            subtitle="They sign in with the host employer role and see only this employer's learners."
            action={
              manage ? (
                <Button
                  size="sm"
                  variant="secondary"
                  icon={<Plus className="size-4" />}
                  onClick={() => setOpen({ kind: 'member' })}
                >
                  Link a person
                </Button>
              ) : null
            }
          />
          {e.members.length ? (
            <ul className="divide-y divide-line">
              {e.members.map((m) => (
                <li key={m.id} className="flex items-center justify-between gap-3 py-2">
                  <div className="min-w-0">
                    <div className="font-medium text-ink">{m.name}</div>
                    <div className="text-small text-muted">{m.email}</div>
                  </div>
                  {manage ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      loading={unlink.isPending && unlink.variables === m.id}
                      onClick={() => unlink.mutate(m.id)}
                    >
                      Unlink<span className="sr-only"> {m.name}</span>
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-small text-muted">Nobody linked yet. Invite them from Users & roles first.</p>
          )}
        </Card>
      </div>

      {open?.kind === 'employer' && lists.data ? (
        <EmployerDialog setas={lists.data.setas} bbbee={lists.data.bbbee} employer={e} onClose={() => setOpen(null)} />
      ) : null}
      {open?.kind === 'site' && lists.data ? (
        <SiteDialog employerId={e.id} site={open.site} setas={lists.data.setas} onClose={() => setOpen(null)} />
      ) : null}
      {open?.kind === 'mentor' ? (
        <MentorDialog employer={e} mentor={open.mentor} onClose={() => setOpen(null)} />
      ) : null}
      {open?.kind === 'member' ? (
        <MemberDialog employerId={e.id} linked={e.members.map((m) => m.id)} onClose={() => setOpen(null)} />
      ) : null}
    </>
  );
}

function SiteDialog({
  employerId,
  site,
  setas,
  onClose,
}: {
  employerId: string;
  site: Site | null;
  setas: string[];
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [progress, setProgress] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [fileId, setFileId] = useState<string | null>(site?.approval_file_id ?? null);
  const [quals, setQuals] = useState<string[]>(site?.qualifications.map((x) => x.id) ?? []);
  const [form, setForm] = useState({
    name: site?.name ?? '',
    address_line1: site?.address_line1 ?? '',
    city: site?.city ?? '',
    province: site?.province ?? '',
    postal_code: site?.postal_code ?? '',
    approval_number: site?.approval_number ?? '',
    approval_body: site?.approval_body ?? '',
    approved_on: site?.approved_on ?? '',
    approval_expires_on: site?.approval_expires_on ?? '',
    mentor_ratio: String(site?.mentor_ratio ?? 10),
  });
  const programmes = useQuery({
    queryKey: ['programmes'],
    queryFn: () => call<{ data: { qualification: { id: string; title: string } }[] }>('GET', '/programmes'),
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...clean(form),
        mentor_ratio: Number(form.mentor_ratio),
        approved_qualification_ids: quals,
        approval_file_id: fileId,
      };
      return site
        ? call('PATCH', `/employers/${employerId}/sites/${site.id}`, body)
        : call('POST', `/employers/${employerId}/sites`, body);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['employers'] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const pick = async (file: File | undefined) => {
    if (!file) return;
    setUploadError(null);
    if (file.size > 10 * 1024 * 1024) return setUploadError('Files must be 10 MB or smaller.');
    setProgress(0);
    try {
      setFileId((await uploadFile('workplace_approval', file, setProgress)).id);
    } catch (e) {
      setUploadError(errorMessage(e));
    } finally {
      setProgress(null);
    }
  };

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={site ? 'Change workplace' : 'Add a workplace'}
      description="As on the SETA's workplace approval letter."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <Field label="Site name" required value={form.name} onChange={set('name')} error={err('name')} />
        <Field
          label="Street address"
          value={form.address_line1}
          onChange={set('address_line1')}
          error={err('address_line1')}
        />
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Town or city" value={form.city} onChange={set('city')} error={err('city')} />
          <Select label="Province" value={form.province} onChange={set('province')}>
            <option value="">Choose…</option>
            {PROVINCES.map((p) => (
              <option key={p}>{p}</option>
            ))}
          </Select>
          <Field
            label="Postal code"
            inputMode="numeric"
            value={form.postal_code}
            onChange={set('postal_code')}
            error={err('postal_code')}
          />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Approval number"
            value={form.approval_number}
            onChange={set('approval_number')}
            error={err('approval_number')}
          />
          <Select label="Approved by" value={form.approval_body} onChange={set('approval_body')}>
            <option value="">Choose…</option>
            {setas.map((s) => (
              <option key={s}>{s}</option>
            ))}
          </Select>
          <Field
            label="Approved on"
            type="date"
            value={form.approved_on}
            onChange={set('approved_on')}
            error={err('approved_on')}
          />
          <Field
            label="Approval ends"
            type="date"
            value={form.approval_expires_on}
            onChange={set('approval_expires_on')}
            error={err('approval_expires_on') ?? err('approval_body')}
          />
          <Field
            label="Learners per mentor"
            type="number"
            min={1}
            max={50}
            hint="As your SETA allows. Often 1 mentor to 10 learners."
            value={form.mentor_ratio}
            onChange={set('mentor_ratio')}
            error={err('mentor_ratio')}
          />
        </div>
        <fieldset className="grid gap-1.5 text-small">
          <legend className="mb-1 font-medium">Approved for</legend>
          {programmes.data?.data.length ? (
            programmes.data.data.map(({ qualification: x }) => (
              <label key={x.id} className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={quals.includes(x.id)}
                  onChange={(ev) => setQuals(ev.target.checked ? [...quals, x.id] : quals.filter((v) => v !== x.id))}
                />
                {x.title}
              </label>
            ))
          ) : (
            <span className="text-muted">Add programmes first; workplaces are approved for those.</span>
          )}
          {err('approved_qualification_ids') ? (
            <span className="text-danger">{err('approved_qualification_ids')}</span>
          ) : null}
        </fieldset>
        <label className="grid gap-1.5 text-small">
          <span className="font-medium">Approval letter {fileId ? '(uploaded)' : ''}</span>
          <input
            type="file"
            accept="application/pdf,image/jpeg,image/png"
            onChange={(ev) => void pick(ev.target.files?.[0])}
          />
          {progress !== null ? <span className="text-muted">Uploading… {Math.round(progress * 100)}%</span> : null}
          {uploadError ? <span className="text-danger">{uploadError}</span> : null}
        </label>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending} disabled={progress !== null}>
            {site ? 'Save' : 'Add workplace'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function MentorDialog({
  employer,
  mentor,
  onClose,
}: {
  employer: EmployerDetail;
  mentor: Mentor | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [sites, setSites] = useState<string[]>(
    mentor?.site_ids ?? (employer.sites.length === 1 ? [employer.sites[0]!.id] : []),
  );
  const [active, setActive] = useState(mentor?.active ?? true);
  const [form, setForm] = useState({
    name: mentor?.name ?? '',
    designation: mentor?.designation ?? '',
    years_experience: String(mentor?.years_experience ?? ''),
    highest_qualification: mentor?.highest_qualification ?? '',
    qualification_nqf_level: String(mentor?.qualification_nqf_level ?? ''),
    email: mentor?.email ?? '',
    phone: mentor?.phone ?? '',
  });
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...clean(form),
        years_experience: form.years_experience === '' ? null : Number(form.years_experience),
        qualification_nqf_level: form.qualification_nqf_level === '' ? null : Number(form.qualification_nqf_level),
        site_ids: sites,
        active,
      };
      return mentor
        ? call('PATCH', `/employers/${employer.id}/mentors/${mentor.id}`, body)
        : call('POST', `/employers/${employer.id}/mentors`, body);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['employers'] });
      onClose();
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={mentor ? 'Change mentor' : 'Add a workplace mentor'}
      description="Their experience is recorded against the curriculum's workplace requirements."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Full name" required value={form.name} onChange={set('name')} error={err('name')} />
          <Field
            label="Job title"
            required
            value={form.designation}
            onChange={set('designation')}
            error={err('designation')}
          />
          <Field
            label="Years in the trade"
            type="number"
            min={0}
            max={60}
            required
            value={form.years_experience}
            onChange={set('years_experience')}
            error={err('years_experience')}
          />
          <Field
            label="NQF level of highest qualification"
            type="number"
            min={1}
            max={10}
            value={form.qualification_nqf_level}
            onChange={set('qualification_nqf_level')}
            error={err('qualification_nqf_level')}
          />
        </div>
        <Field
          label="Highest qualification"
          value={form.highest_qualification}
          onChange={set('highest_qualification')}
          error={err('highest_qualification')}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Email" type="email" value={form.email} onChange={set('email')} error={err('email')} />
          <Field label="Phone" type="tel" value={form.phone} onChange={set('phone')} error={err('phone')} />
        </div>
        <fieldset className="grid gap-1.5 text-small">
          <legend className="mb-1 font-medium">Mentors at</legend>
          {employer.sites.map((s) => (
            <label key={s.id} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={sites.includes(s.id)}
                onChange={(ev) => setSites(ev.target.checked ? [...sites, s.id] : sites.filter((v) => v !== s.id))}
              />
              {s.name}
            </label>
          ))}
          {err('site_ids') ? <span className="text-danger">{err('site_ids')}</span> : null}
        </fieldset>
        {mentor ? (
          <label className="flex items-center gap-2 text-small">
            <input type="checkbox" checked={active} onChange={(ev) => setActive(ev.target.checked)} />
            Still mentoring (inactive mentors don't count towards capacity)
          </label>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            {mentor ? 'Save' : 'Add mentor'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function MemberDialog({ employerId, linked, onClose }: { employerId: string; linked: string[]; onClose: () => void }) {
  const qc = useQueryClient();
  const [person, setPerson] = useState('');
  const people = useQuery({
    queryKey: ['users', 'all'],
    queryFn: () =>
      call<{ data: { id: string; name: string }[] }>('GET', '/users', undefined, { per_page: 100 }).then((r) => r.data),
  });
  const m = useMutation({
    mutationFn: () => call('POST', `/employers/${employerId}/members`, { user_id: person }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['employers', employerId] });
      onClose();
    },
  });
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Link a person"
      description="Someone already invited, who acts for this employer."
    >
      <div className="grid gap-3">
        <Select label="Person" value={person} onChange={(e) => setPerson(e.target.value)}>
          <option value="">Choose…</option>
          {people.data
            ?.filter((p) => !linked.includes(p.id))
            .map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
        </Select>
        {m.error ? <Alert tone="danger">{errorMessage(m.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!person} loading={m.isPending} onClick={() => m.mutate()}>
            Link
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
