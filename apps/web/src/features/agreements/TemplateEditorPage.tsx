import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { useRef, useState } from 'react';
import { Alert, Badge, Button, Card, CardHeader, Field, PageHeader, Select, Skeleton, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { useCan } from '@/lib/session';
import { AgreementText } from './AgreementText';
import { KIND_LABEL, ROLE_LABEL, type Role, type Template } from './types';

/** Edit a draft; publishing freezes it. Editing a published version starts the next draft. */
export function TemplateEditorPage() {
  const { id } = useParams({ from: '/app/agreements/templates/$id' });
  const q = useQuery({
    queryKey: ['agreement-templates', id],
    queryFn: () => call<{ data: Template; fields: Record<string, string> }>('GET', `/agreement-templates/${id}`),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;

  // Keyed by version, so a newly started version opens with its own wording.
  return <Editor key={q.data.data.id} template={q.data.data} fields={q.data.fields} />;
}

function Editor({ template: t, fields }: { template: Template; fields: Record<string, string> }) {
  const id = t.id;
  const manage = useCan()('agreements.manage');
  const qc = useQueryClient();
  const toast = useToast();
  const navigate = useNavigate();
  const area = useRef<HTMLTextAreaElement>(null);
  const [form, setForm] = useState({
    name: t.name,
    kind: t.kind,
    body: t.body,
    parties: t.parties,
    advanced_signature: t.advanced_signature,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [preview, setPreview] = useState(false);

  const save = useMutation({
    mutationFn: () => call<{ data: Template }>('PATCH', `/agreement-templates/${id}`, form),
    onSuccess: (r) => {
      setErrors({});
      void qc.invalidateQueries({ queryKey: ['agreement-templates'] });
      if (r.data.id !== id) {
        toast(`Started version ${r.data.version}`);
        void navigate({ to: '/agreements/templates/$id', params: { id: r.data.id } });
      } else toast('Draft saved');
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  });
  const publish = useMutation({
    mutationFn: async () => {
      await call('PATCH', `/agreement-templates/${id}`, form);
      return call('POST', `/agreement-templates/${id}/publish`);
    },
    onSuccess: () => {
      toast('Published');
      void qc.invalidateQueries({ queryKey: ['agreement-templates'] });
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? e.fields : {});
      toast(errorMessage(e), 'error');
    },
  });

  const draft = t.status === 'draft';
  const err = (k: string) => errors[k]?.[0] ?? null;
  const insert = (field: string) => {
    const el = area.current;
    const tag = `{{${field}}}`;
    const at = el?.selectionStart ?? form.body.length;
    setForm({ ...form, body: form.body.slice(0, at) + tag + form.body.slice(el?.selectionEnd ?? at) });
    requestAnimationFrame(() => el?.focus());
  };
  const toggleParty = (r: Role) =>
    setForm({
      ...form,
      parties: form.parties.includes(r) ? form.parties.filter((p) => p !== r) : ([...form.parties, r] as Role[]),
    });

  return (
    <>
      <Link
        to="/agreements/templates"
        className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink"
      >
        <ArrowLeft className="size-4" /> Wording
      </Link>
      <PageHeader
        eyebrow={`${KIND_LABEL[t.kind]} · version ${t.version}`}
        title={t.name}
        description={
          draft
            ? 'A draft: change it freely, then publish it to use it.'
            : 'Published versions never change. Saving a change starts the next version as a draft.'
        }
        actions={
          <div className="flex items-center gap-2">
            <Badge tone={t.status === 'published' ? 'success' : t.status === 'draft' ? 'warning' : 'neutral'} dot>
              {t.status === 'published' ? 'In use' : t.status === 'draft' ? 'Draft' : 'Retired'}
            </Badge>
            {manage ? (
              <>
                <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>
                  {draft ? 'Save draft' : 'Start next version'}
                </Button>
                {draft ? (
                  <Button loading={publish.isPending} onClick={() => publish.mutate()}>
                    Publish
                  </Button>
                ) : null}
              </>
            ) : null}
          </div>
        }
      />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <Card>
          <div className="mb-3 grid gap-3 sm:grid-cols-2">
            <Field
              label="Name"
              value={form.name}
              disabled={!manage}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              error={err('name')}
            />
            <Select
              label="Kind"
              value={form.kind}
              disabled={!manage}
              onChange={(e) => setForm({ ...form, kind: e.target.value })}
            >
              {Object.entries(KIND_LABEL).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          </div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-small font-medium" id="wording-label">
              Wording
            </span>
            <button
              type="button"
              className="text-small text-primary-700 hover:underline"
              onClick={() => setPreview(!preview)}
            >
              {preview ? 'Edit' : 'Preview'}
            </button>
          </div>
          {preview ? (
            <div className="rounded-lg border border-line p-4">
              <AgreementText text={form.body} />
            </div>
          ) : (
            <textarea
              ref={area}
              aria-labelledby="wording-label"
              readOnly={!manage}
              className="min-h-[28rem] w-full rounded-lg border border-line bg-surface p-3 font-mono text-[13px] leading-relaxed"
              value={form.body}
              onChange={(e) => setForm({ ...form, body: e.target.value })}
            />
          )}
          {err('body') ? <Alert tone="danger">{err('body')}</Alert> : null}
          <p className="mt-2 text-small text-muted">
            Start headings with # or ##, number clauses (1.1, 1.2), and leave a blank line between paragraphs.
          </p>
        </Card>
        <div className="grid content-start gap-4">
          <Card>
            <CardHeader title="Who signs" />
            <div className="grid gap-2 text-small">
              {(Object.keys(ROLE_LABEL) as Role[]).map((r) => (
                <label key={r} className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.parties.includes(r)}
                    disabled={!manage || r === 'provider'}
                    onChange={() => toggleParty(r)}
                  />
                  {ROLE_LABEL[r]}
                </label>
              ))}
              {err('parties') ? <span className="text-danger-700">{err('parties')}</span> : null}
              <label className="mt-2 flex items-start gap-2 border-t border-line pt-3">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={form.advanced_signature}
                  disabled={!manage}
                  onChange={(e) => setForm({ ...form, advanced_signature: e.target.checked })}
                />
                <span>
                  Advanced e-signature (LAWtrust)
                  <span className="block text-muted">Only where a SETA or a law requires one.</span>
                </span>
              </label>
            </div>
          </Card>
          <Card>
            <CardHeader title="Fields" subtitle="Filled in when you send. Click to insert." />
            <ul className="grid gap-1">
              {Object.entries(fields).map(([k, label]) => (
                <li key={k}>
                  <button
                    type="button"
                    disabled={!manage || preview}
                    onClick={() => insert(k)}
                    className="w-full rounded-md px-2 py-1 text-left text-small hover:bg-line-soft disabled:opacity-60"
                  >
                    <span className="text-ink">{label}</span>{' '}
                    <span className="font-mono text-[11px] text-muted">{`{{${k}}}`}</span>
                  </button>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
    </>
  );
}
