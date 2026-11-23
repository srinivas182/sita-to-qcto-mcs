import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Dialog, Field, Select } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { uploadFile } from '@/lib/upload';
import type { AgreementDetail, Candidate, FundersResponse, TrancheForm } from './types';

const blankTranche = (): TrancheForm => ({ share_pct: '', due_on: null, milestones: [], evidence: [] });

/** Create or change a grant agreement: funder, amounts, the learners it funds and its tranches. */
export function AgreementDialog({
  cohortId,
  detail,
  onClose,
}: {
  cohortId: string;
  detail?: AgreementDetail;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const funders = useQuery({ queryKey: ['funders'], queryFn: () => call<FundersResponse>('GET', '/funders') });
  const candidates = useQuery({
    queryKey: ['grant-candidates', cohortId],
    queryFn: () => call<{ data: Candidate[] }>('GET', `/cohorts/${cohortId}/grant-candidates`).then((r) => r.data),
  });
  const a = detail?.agreement;
  const [form, setForm] = useState({
    funder_id: a?.funder_id ?? '',
    reference: a?.reference ?? '',
    learners: a ? String(a.learners) : '',
    amount_per_learner: a ? String(a.amount_per_learner_cents / 100) : '',
    funding_kind: a?.funding_kind ?? 'full',
    disability_allowance: a?.disability_allowance_cents != null ? String(a.disability_allowance_cents / 100) : '',
    starts_on: a?.starts_on ?? '',
    ends_on: a?.ends_on ?? '',
    moa_file_id: a?.moa_file_id ?? null,
  });
  const [chosen, setChosen] = useState<string[]>(detail?.form.enrolment_ids ?? []);
  const [disabled, setDisabled] = useState<string[]>(detail?.form.disabled_ids ?? []);
  const [tranches, setTranches] = useState<TrancheForm[]>(detail?.form.tranches ?? [blankTranche()]);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [newFunder, setNewFunder] = useState('');

  const save = useMutation({
    mutationFn: () =>
      call(a ? 'PUT' : 'POST', a ? `/grant-agreements/${a.id}` : `/cohorts/${cohortId}/grant-agreements`, {
        ...form,
        disability_allowance: form.disability_allowance === '' ? null : form.disability_allowance,
        enrolment_ids: chosen,
        disabled_ids: disabled.filter((d) => chosen.includes(d)),
        tranches,
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['grants', cohortId] });
      if (a) void qc.invalidateQueries({ queryKey: ['grant', a.id] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const addFunder = useMutation({
    mutationFn: () => call<{ data: { id: string } }>('POST', '/funders', { name: newFunder, kind: 'seta' }),
    onSuccess: (r) => {
      setForm((f) => ({ ...f, funder_id: r.data.id }));
      setNewFunder('');
      void qc.invalidateQueries({ queryKey: ['funders'] });
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const upload = async (f: File | undefined) => {
    if (!f) return;
    setUploading(true);
    try {
      const up = await uploadFile('grant_document', f);
      setForm((x) => ({ ...x, moa_file_id: up.id }));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setUploading(false);
    }
  };
  const setTranche = (i: number, t: Partial<TrancheForm>) =>
    setTranches((all) => all.map((x, j) => (j === i ? { ...x, ...t } : x)));
  const milestones = funders.data?.milestones ?? {};
  const total = tranches.reduce((s, t) => s + (Number(t.share_pct) || 0), 0);

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={a ? `Grant agreement ${a.reference}` : 'Add a grant agreement'}
      description="Record the MoA as signed: what it pays per learner, which learners it funds, and when each tranche is paid."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={uploading} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label="Funder"
            value={form.funder_id}
            onChange={(e) => setForm({ ...form, funder_id: e.target.value })}
          >
            <option value="">Choose…</option>
            {funders.data?.data.map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
          </Select>
          <div className="flex items-end gap-2">
            <Field
              label="Or add a funder"
              value={newFunder}
              onChange={(e) => setNewFunder(e.target.value)}
              placeholder="e.g. CETA"
            />
            <Button
              variant="secondary"
              disabled={!newFunder.trim()}
              loading={addFunder.isPending}
              onClick={() => addFunder.mutate()}
            >
              Add
            </Button>
          </div>
          <Field
            label="Agreement reference"
            value={form.reference}
            onChange={(e) => setForm({ ...form, reference: e.target.value })}
          />
          <Field
            label="Learners funded"
            type="number"
            min={1}
            value={form.learners}
            onChange={(e) => setForm({ ...form, learners: e.target.value })}
          />
          <Field
            label="Amount per learner (R)"
            type="number"
            min={0}
            step="0.01"
            value={form.amount_per_learner}
            onChange={(e) => setForm({ ...form, amount_per_learner: e.target.value })}
          />
          <Select
            label="Funding"
            value={form.funding_kind}
            onChange={(e) => setForm({ ...form, funding_kind: e.target.value as 'full' | 'co' })}
          >
            <option value="full">Fully funded</option>
            <option value="co">Co-funded</option>
          </Select>
          <Field
            label="Starts"
            type="date"
            value={form.starts_on}
            onChange={(e) => setForm({ ...form, starts_on: e.target.value })}
          />
          <Field
            label="Ends"
            type="date"
            value={form.ends_on}
            onChange={(e) => setForm({ ...form, ends_on: e.target.value })}
          />
          <Field
            label="Disability allowance per learner (R, optional)"
            type="number"
            min={0}
            step="0.01"
            value={form.disability_allowance}
            onChange={(e) => setForm({ ...form, disability_allowance: e.target.value })}
          />
          <div className="grid gap-1.5">
            <label htmlFor="moa" className="text-small font-medium text-ink">
              Signed agreement (PDF){form.moa_file_id ? ': uploaded' : ''}
            </label>
            <input
              id="moa"
              type="file"
              accept="application/pdf,image/jpeg,image/png"
              onChange={(e) => void upload(e.target.files?.[0])}
            />
          </div>
        </div>

        <fieldset className="grid gap-2">
          <legend className="mb-1 text-small font-medium text-ink">
            Learners on this agreement ({chosen.length}
            {form.learners ? ` of ${form.learners}` : ''})
          </legend>
          {candidates.data?.length === 0 ? <p className="text-small text-muted">No learners enrolled yet.</p> : null}
          <ul className="grid max-h-56 gap-2 overflow-y-auto">
            {candidates.data?.map((c) => {
              const on = chosen.includes(c.enrolment_id);
              const elsewhere = c.agreement_ids.filter((x) => x !== a?.id).length > 0;
              return (
                <li key={c.enrolment_id} className="flex flex-wrap items-center gap-3 text-small">
                  <label className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      className="size-5"
                      checked={on}
                      onChange={() =>
                        setChosen((x) => (on ? x.filter((y) => y !== c.enrolment_id) : [...x, c.enrolment_id]))
                      }
                    />
                    {c.name}
                    {elsewhere ? <span className="text-muted"> (on another agreement)</span> : null}
                  </label>
                  {on ? (
                    <label className="flex items-center gap-2 text-muted">
                      <input
                        type="checkbox"
                        className="size-5"
                        checked={disabled.includes(c.enrolment_id)}
                        onChange={() =>
                          setDisabled((x) =>
                            x.includes(c.enrolment_id) ? x.filter((y) => y !== c.enrolment_id) : [...x, c.enrolment_id],
                          )
                        }
                      />
                      Disability allowance<span className="sr-only"> for {c.name}</span>
                    </label>
                  ) : null}
                </li>
              );
            })}
          </ul>
        </fieldset>

        <fieldset className="grid gap-3">
          <legend className="mb-1 text-small font-medium text-ink">
            Tranches ({total}% of the amount per learner)
          </legend>
          {tranches.map((t, i) => (
            <div key={i} className="grid gap-2 rounded-lg border border-line p-3">
              <div className="flex flex-wrap items-end gap-3">
                <p className="font-medium text-ink">Tranche {i + 1}</p>
                <Field
                  label={`Share % for tranche ${i + 1}`}
                  type="number"
                  min={1}
                  max={100}
                  className="w-28"
                  value={t.share_pct}
                  onChange={(e) => setTranche(i, { share_pct: e.target.value })}
                />
                <Field
                  label={`Due date for tranche ${i + 1} (optional)`}
                  type="date"
                  value={t.due_on ?? ''}
                  onChange={(e) => setTranche(i, { due_on: e.target.value || null })}
                />
                {tranches.length > 1 ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<Trash2 className="size-5" />}
                    onClick={() => setTranches((x) => x.filter((_, j) => j !== i))}
                  >
                    Remove<span className="sr-only"> tranche {i + 1}</span>
                  </Button>
                ) : null}
              </div>
              <ul className="grid gap-2 text-small">
                {Object.entries(milestones).map(([key, label]) => {
                  const m = t.milestones.find((x) => x.key === key);
                  return (
                    <li key={key} className="flex flex-wrap items-center gap-2">
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          className="size-5"
                          checked={!!m}
                          onChange={() =>
                            setTranche(i, {
                              milestones: m
                                ? t.milestones.filter((x) => x.key !== key)
                                : [...t.milestones, key === 'registers' ? { key, months: 1 } : { key }],
                            })
                          }
                        />
                        {label}
                      </label>
                      {m && key === 'registers' ? (
                        <label className="flex items-center gap-1 text-muted">
                          months
                          <input
                            type="number"
                            min={1}
                            max={36}
                            aria-label={`Months of registers for tranche ${i + 1}`}
                            className="h-8 w-16 rounded border border-line px-2"
                            value={m.months ?? 1}
                            onChange={(e) =>
                              setTranche(i, {
                                milestones: t.milestones.map((x) =>
                                  x.key === key ? { ...x, months: Number(e.target.value) } : x,
                                ),
                              })
                            }
                          />
                        </label>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
              <div className="grid gap-1">
                <p className="text-small text-muted">
                  Evidence the platform doesn&apos;t hold (uploaded later, before claiming):
                </p>
                {t.evidence.map((ev, k) => (
                  <div key={k} className="flex items-end gap-2">
                    <Field
                      label={`Evidence ${k + 1} for tranche ${i + 1}`}
                      value={ev.title}
                      onChange={(e) =>
                        setTranche(i, {
                          evidence: t.evidence.map((x, j) => (j === k ? { ...x, title: e.target.value } : x)),
                        })
                      }
                    />
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setTranche(i, { evidence: t.evidence.filter((_, j) => j !== k) })}
                    >
                      Remove<span className="sr-only"> evidence {k + 1}</span>
                    </Button>
                  </div>
                ))}
                <div>
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<Plus className="size-5" />}
                    onClick={() => setTranche(i, { evidence: [...t.evidence, { title: '', file_id: null }] })}
                  >
                    Evidence item<span className="sr-only"> for tranche {i + 1}</span>
                  </Button>
                </div>
              </div>
            </div>
          ))}
          <div>
            <Button
              size="sm"
              variant="secondary"
              icon={<Plus className="size-5" />}
              onClick={() => setTranches((x) => [...x, blankTranche()])}
            >
              Add a tranche
            </Button>
          </div>
        </fieldset>
      </div>
    </Dialog>
  );
}
