import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { Alert, Button, Dialog, Field, Select } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { uploadFile } from '@/lib/upload';
import type { QualificationSummary } from '@/features/catalogue/types';
import { BODIES, ROLES, type Credential } from './types';

/**
 * Add or change a registration. `personId` null means "my own" (self-service).
 * Any change to a checked registration sends it back for checking.
 */
export function CredentialDialog({
  personId,
  credential,
  onClose,
}: {
  personId: string | null;
  credential: Credential | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const listId = useId();
  const [form, setForm] = useState({
    role: credential?.role ?? 'assessor',
    body: credential?.body ?? 'CETA',
    registration_number: credential?.registration_number ?? '',
    starts_on: credential?.starts_on ?? '',
    expires_on: credential?.expires_on ?? '',
  });
  const [scope, setScope] = useState<string[]>(credential?.qualifications.map((q) => q.id) ?? []);
  const [fileId, setFileId] = useState<string | null>(credential?.file_id ?? null);
  const [fileName, setFileName] = useState<string | null>(credential?.file_id ? 'Registration letter' : null);
  const [progress, setProgress] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const input = useRef<HTMLInputElement>(null);

  // The provider's own programmes first; the whole catalogue is searchable when needed.
  const programmes = useQuery({
    queryKey: ['programmes'],
    queryFn: () =>
      call<{ data: { qualification: QualificationSummary }[] }>('GET', '/programmes').then((r) =>
        r.data.map((p) => p.qualification),
      ),
    retry: false,
  });
  const catalogue = useQuery({
    queryKey: ['catalogue', '', ''],
    queryFn: () => call<{ data: QualificationSummary[] }>('GET', '/catalogue').then((r) => r.data),
    enabled: programmes.isError || (programmes.data?.length ?? 0) === 0,
  });
  const options = programmes.data?.length ? programmes.data : (catalogue.data ?? []);

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        starts_on: form.starts_on || null,
        expires_on: form.expires_on || null,
        qualification_ids: scope,
        file_id: fileId,
      };
      if (credential) return call('PATCH', `/credentials/${credential.id}`, body);
      return call('POST', personId ? `/people/${personId}/credentials` : '/me/credentials', body);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['credentials'] });
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
      const uploaded = await uploadFile('credential_document', file, setProgress);
      setFileId(uploaded.id);
      setFileName(file.name);
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
      title={credential ? 'Change registration' : 'Add a registration'}
      description="As on the registration letter. It counts once someone else has checked it against the letter."
    >
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Registered as" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
            {Object.entries(ROLES).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </Select>
          <Field
            label="Registered with"
            list={listId}
            value={form.body}
            onChange={(e) => setForm({ ...form, body: e.target.value })}
            error={errors.body?.[0]}
          />
          <datalist id={listId}>
            {BODIES.map((b) => (
              <option key={b} value={b} />
            ))}
          </datalist>
        </div>
        <Field
          label="Registration number"
          required
          value={form.registration_number}
          onChange={(e) => setForm({ ...form, registration_number: e.target.value })}
          error={errors.registration_number?.[0]}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Valid from"
            type="date"
            value={form.starts_on}
            onChange={(e) => setForm({ ...form, starts_on: e.target.value })}
            error={errors.starts_on?.[0]}
          />
          <Field
            label="Valid until"
            type="date"
            value={form.expires_on}
            onChange={(e) => setForm({ ...form, expires_on: e.target.value })}
            error={errors.expires_on?.[0]}
          />
        </div>
        <fieldset className="grid gap-2">
          <legend className="text-small font-medium text-ink">Qualifications it covers</legend>
          <div className="grid max-h-44 gap-1.5 overflow-auto rounded-lg border border-line p-2">
            {options.map((q) => (
              <label key={q.id} className="flex items-start gap-2 text-small">
                <input
                  type="checkbox"
                  className="mt-0.5"
                  checked={scope.includes(q.id)}
                  onChange={(e) => setScope(e.target.checked ? [...scope, q.id] : scope.filter((x) => x !== q.id))}
                />
                <span>
                  {q.title} {q.saqa_id ? <span className="text-muted">(SAQA {q.saqa_id})</span> : null}
                </span>
              </label>
            ))}
            {options.length === 0 ? <p className="text-small text-muted">No qualifications yet.</p> : null}
          </div>
          {errors.qualification_ids ? <p className="text-small text-danger">{errors.qualification_ids[0]}</p> : null}
        </fieldset>
        <div className="flex items-center justify-between gap-3 rounded-lg border border-dashed border-line p-3">
          <span className="flex min-w-0 items-center gap-2 text-small">
            <FileText className="size-4 shrink-0 text-muted" aria-hidden />
            <span className="truncate">{fileName ?? 'Registration letter (PDF or photo, up to 10 MB)'}</span>
          </span>
          <Button
            type="button"
            size="sm"
            variant="secondary"
            icon={<Upload className="size-4" />}
            loading={progress !== null}
            onClick={() => input.current?.click()}
          >
            {fileId ? 'Replace' : 'Upload'}
          </Button>
          <input
            ref={input}
            type="file"
            className="sr-only"
            accept="application/pdf,image/jpeg,image/png"
            aria-label="Registration letter"
            onChange={(e) => void pick(e.target.files?.[0])}
          />
        </div>
        {uploadError ? (
          <p className="text-small text-danger" role="alert">
            {uploadError}
          </p>
        ) : null}
        {credential?.status === 'verified' ? (
          <Alert tone="info">Saving a change sends this registration back for checking.</Alert>
        ) : null}
        {save.error && !Object.keys(errors).length ? <Alert tone="danger">{errorMessage(save.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending} disabled={progress !== null}>
            Save
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
