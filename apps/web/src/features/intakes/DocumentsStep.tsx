import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Upload } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Button, Card, Field } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { uploadFile } from '@/lib/upload';
import { photoProblems, reqState, type DocumentRule, type LearnerDocument, type Requirement } from './documents';

interface Mine {
  documents: LearnerDocument[];
  needed: {
    application_id: string;
    reference: string;
    intake: string | null;
    complete: boolean;
    items: Requirement[];
  }[];
  types: DocumentRule[];
}

/** After applying: the documents this application needs, each added with its certification details. */
export function DocumentsStep({ applicationId }: { applicationId: string }) {
  const q = useQuery({
    queryKey: ['me', 'documents'],
    queryFn: () => call<{ data: Mine }>('GET', '/me/documents').then((r) => r.data),
  });
  const needed = q.data?.needed.find((n) => n.application_id === applicationId);
  if (!q.data || !needed) return null;
  if (!needed.items.length) return null;

  return (
    <Card>
      <h2 className="mb-1 font-semibold text-ink">Add your documents</h2>
      <p className="mb-4 text-small text-muted">
        Take a clear photo of each, flat and in good light, or upload a scan. You can also add them later from the app.
      </p>
      {needed.complete ? <Alert tone="success">Everything is in. The provider will check your documents.</Alert> : null}
      <ul className="grid gap-3">
        {needed.items.map((item) => (
          <li key={item.type}>
            <DocumentItem item={item} rule={q.data.types.find((t) => t.type === item.type)} />
          </li>
        ))}
      </ul>
    </Card>
  );
}

function DocumentItem({ item, rule }: { item: Requirement; rule: DocumentRule | undefined }) {
  const qc = useQueryClient();
  const [open, setOpen] = useState(item.state === 'missing' || item.state === 'rejected' || item.state === 'expired');
  const [file, setFile] = useState<File | null>(null);
  const [warnings, setWarnings] = useState<string[]>([]);
  const [progress, setProgress] = useState<number | null>(null);
  const [form, setForm] = useState({ certified: rule?.certified ?? false, certified_on: '', certified_by: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const add = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('Choose a photo or file first.');
      setProgress(0);
      const uploaded = await uploadFile('learner_document', file, setProgress);
      return call('POST', '/me/documents', {
        type: item.type,
        file_id: uploaded.id,
        certified: form.certified,
        certified_on: form.certified ? form.certified_on : null,
        certified_by: form.certified_by || null,
      });
    },
    onSuccess: () => {
      setErrors({});
      setOpen(false);
      void qc.invalidateQueries({ queryKey: ['me', 'documents'] });
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { file: [errorMessage(e)] }),
    onSettled: () => setProgress(null),
  });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const pick = async (f: File | undefined) => {
    setFile(f ?? null);
    setWarnings(f ? await photoProblems(f) : []);
  };

  return (
    <div className="rounded-lg border border-line p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium text-ink">{item.label}</span>
        <Badge tone={reqState[item.state].tone} dot>
          {item.state === 'pending' ? 'Received' : reqState[item.state].label}
        </Badge>
      </div>
      {item.problem ? <p className="mt-1 text-small text-danger">{item.problem}</p> : null}
      {open ? (
        <form
          className="mt-3 grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
        >
          <label className="grid gap-1.5 text-small">
            <span className="font-medium">Photo or file</span>
            <input
              type="file"
              accept="image/jpeg,image/png,application/pdf"
              capture="environment"
              onChange={(e) => void pick(e.target.files?.[0])}
            />
          </label>
          {warnings.map((w) => (
            <Alert key={w} tone="warning">
              {w}
            </Alert>
          ))}
          {rule?.certified ? (
            <>
              <label className="flex items-center gap-2 text-small">
                <input
                  type="checkbox"
                  checked={form.certified}
                  onChange={(e) => setForm({ ...form, certified: e.target.checked })}
                />
                This is a certified copy
              </label>
              {form.certified ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field
                    label="Date on the certification stamp"
                    type="date"
                    required
                    value={form.certified_on}
                    onChange={(e) => setForm({ ...form, certified_on: e.target.value })}
                    error={err('certified_on')}
                    hint={rule.max_age_days ? `Certified in the last ${rule.max_age_days} days` : undefined}
                  />
                  <Field
                    label="Certified by (e.g. SAPS Orlando)"
                    value={form.certified_by}
                    onChange={(e) => setForm({ ...form, certified_by: e.target.value })}
                  />
                </div>
              ) : (
                <p className="text-small text-muted">
                  This document must be a certified copy. A police station or commissioner of oaths can certify it for
                  free.
                </p>
              )}
            </>
          ) : null}
          {err('file') || err('file_id') || err('type') ? (
            <Alert tone="danger">{err('file') ?? err('file_id') ?? err('type')}</Alert>
          ) : null}
          <Button type="submit" icon={<Upload className="size-4" />} disabled={!file} loading={add.isPending}>
            {progress !== null ? `Uploading ${Math.round(progress * 100)}%` : 'Add document'}
          </Button>
        </form>
      ) : item.state !== 'accepted' ? (
        <button type="button" className="mt-2 text-small text-primary-700 underline" onClick={() => setOpen(true)}>
          Replace
        </button>
      ) : (
        <p className="mt-1 text-small text-success">
          <CheckCircle2 className="mr-1 inline size-4 align-text-bottom" />
          Checked and accepted
        </p>
      )}
    </div>
  );
}
