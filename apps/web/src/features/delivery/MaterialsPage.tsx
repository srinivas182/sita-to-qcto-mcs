import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, FileText, Film, Image as ImageIcon, Link2, Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
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
  useToast,
} from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { uploadFile } from '@/lib/upload';

interface MaterialRow {
  id: string;
  module_id: string | null;
  title: string;
  description: string | null;
  kind: 'document' | 'video' | 'image' | 'link';
  url: string | null;
  file_id: string | null;
  status: 'draft' | 'published';
  version: number;
  low_status: 'queued' | 'ready' | 'failed' | 'none' | null;
  duration_s: number | null;
  sort: number;
  size: number | null;
  low_size: number | null;
  file_name: string | null;
}
interface Module {
  id: string;
  code: string;
  title: string;
  kind: string;
}

const ICON: Record<MaterialRow['kind'], ReactNode> = {
  document: <FileText className="size-4" aria-hidden />,
  video: <Film className="size-4" aria-hidden />,
  image: <ImageIcon className="size-4" aria-hidden />,
  link: <Link2 className="size-4" aria-hidden />,
};
export const megabytes = (n: number | null) =>
  n == null ? '' : n < 1_000_000 ? `${Math.max(1, Math.round(n / 1000))} KB` : `${(n / 1_000_000).toFixed(1)} MB`;

/** Course materials for a qualification, by module: only enrolled learners see what is published. */
export function MaterialsPage() {
  const { id } = useParams({ from: '/app/programmes/$id/materials' });
  const qc = useQueryClient();
  const toast = useToast();
  const [editing, setEditing] = useState<MaterialRow | 'new' | null>(null);
  const q = useQuery({
    queryKey: ['materials', id],
    queryFn: () =>
      call<{ data: MaterialRow[]; modules: Module[]; kinds: Record<string, string> }>('GET', '/materials', undefined, {
        qualification_id: id,
      }),
  });
  const publish = useMutation({
    mutationFn: (m: MaterialRow) => call('POST', `/materials/${m.id}/publish`, { published: m.status !== 'published' }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['materials', id] }),
    onError: (e) => toast(errorMessage(e), 'error'),
  });
  if (q.isPending) return <Skeleton className="h-64" />;
  if (q.isError) return <Alert tone="danger">{errorMessage(q.error)}</Alert>;
  const { data, modules } = q.data;
  const groups: { key: string; title: string; items: MaterialRow[] }[] = [
    ...modules.map((m) => ({
      key: m.id,
      title: `${m.code} ${m.title}`,
      items: data.filter((x) => x.module_id === m.id),
    })),
    { key: 'none', title: 'For the whole course', items: data.filter((x) => !x.module_id) },
  ].filter((g) => g.items.length);

  return (
    <>
      <Link to="/programmes" className="mb-3 inline-flex items-center gap-1 text-small text-muted hover:text-ink">
        <ArrowLeft className="size-4" /> Programmes
      </Link>
      <PageHeader
        title="Course materials"
        description="Notes, videos, pictures and links by curriculum module. Only learners enrolled on this qualification see what is published, and they can download it for offline use. Videos get a low-data copy automatically."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Add material
          </Button>
        }
      />
      {groups.length === 0 ? (
        <Card>
          <EmptyState title="No materials yet">Add notes, videos or links for each module.</EmptyState>
        </Card>
      ) : (
        <div className="grid gap-4">
          {groups.map((g) => (
            <Card key={g.key}>
              <h2 className="mb-2 text-small font-semibold text-muted">{g.title}</h2>
              <ul className="divide-y divide-line">
                {g.items.map((m) => (
                  <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <button
                      type="button"
                      className="flex min-w-0 items-center gap-2 text-left"
                      onClick={() => setEditing(m)}
                    >
                      <span className="text-muted">{ICON[m.kind]}</span>
                      <span className="min-w-0">
                        <span className="block truncate font-medium text-ink">{m.title}</span>
                        <span className="block text-small text-muted">
                          {q.data.kinds[m.kind]}
                          {m.size ? ` · ${megabytes(m.size)}` : ''}
                          {m.kind === 'video'
                            ? m.low_status === 'ready'
                              ? ` · low-data copy ${megabytes(m.low_size)}`
                              : m.low_status === 'queued'
                                ? ' · making the low-data copy'
                                : ' · no low-data copy'
                            : ''}
                          {m.version > 1 ? ` · version ${m.version}` : ''}
                        </span>
                      </span>
                    </button>
                    <div className="flex items-center gap-2">
                      <Badge tone={m.status === 'published' ? 'success' : 'neutral'} dot>
                        {m.status === 'published' ? 'Published' : 'Draft'}
                      </Badge>
                      <Button size="sm" variant="ghost" onClick={() => publish.mutate(m)}>
                        {m.status === 'published' ? 'Unpublish' : 'Publish'}
                        <span className="sr-only"> {m.title}</span>
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            </Card>
          ))}
        </div>
      )}
      {editing ? (
        <MaterialDialog
          qualificationId={id}
          modules={modules}
          material={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </>
  );
}

function MaterialDialog({
  qualificationId,
  modules,
  material,
  onClose,
}: {
  qualificationId: string;
  modules: Module[];
  material: MaterialRow | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    title: material?.title ?? '',
    description: material?.description ?? '',
    kind: material?.kind ?? 'document',
    module_id: material?.module_id ?? '',
    url: material?.url ?? '',
    file_id: material?.file_id ?? '',
  });
  const [fileName, setFileName] = useState(material?.file_name ?? '');
  const [progress, setProgress] = useState<number | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
  const done = () => {
    void qc.invalidateQueries({ queryKey: ['materials', qualificationId] });
    onClose();
  };
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { title: [errorMessage(e)] });
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        module_id: form.module_id || null,
        description: form.description || null,
        url: form.kind === 'link' ? form.url : null,
        file_id: form.kind === 'link' ? null : form.file_id || null,
      };
      return material
        ? call('PATCH', `/materials/${material.id}`, body)
        : call('POST', '/materials', { ...body, qualification_id: qualificationId });
    },
    onSuccess: done,
    onError: fail,
  });
  const remove = useMutation({
    mutationFn: () => call('DELETE', `/materials/${material!.id}`),
    onSuccess: done,
    onError: fail,
  });
  const pick = async (file: File | undefined) => {
    if (!file) return;
    setErrors({});
    setProgress(0);
    try {
      const up = await uploadFile('learning_material', file, setProgress);
      setForm((f) => ({ ...f, file_id: up.id }));
      setFileName(file.name);
    } catch (e) {
      setErrors({ file_id: [errorMessage(e)] });
    } finally {
      setProgress(null);
    }
  };
  const accept = {
    document: 'application/pdf',
    video: 'video/mp4,video/quicktime,video/webm',
    image: 'image/jpeg,image/png',
    link: '',
  }[form.kind];

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={material ? 'Change material' : 'Add material'}>
      <div className="grid gap-3">
        <Field label="Title" value={form.title} onChange={set('title')} error={errors.title?.[0]} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Kind" value={form.kind} onChange={set('kind')}>
            <option value="document">Document (PDF)</option>
            <option value="video">Video</option>
            <option value="image">Picture</option>
            <option value="link">Link</option>
          </Select>
          <Select label="Module" value={form.module_id} onChange={set('module_id')}>
            <option value="">The whole course</option>
            {modules.map((m) => (
              <option key={m.id} value={m.id}>
                {m.code} {m.title}
              </option>
            ))}
          </Select>
        </div>
        {form.kind === 'link' ? (
          <Field
            label="Address"
            type="url"
            placeholder="https://"
            value={form.url}
            onChange={set('url')}
            error={errors.url?.[0]}
          />
        ) : (
          <div className="grid gap-1.5">
            <label htmlFor="material-file" className="text-small font-medium text-ink">
              File
            </label>
            <input
              id="material-file"
              type="file"
              accept={accept}
              className="text-small"
              onChange={(e) => void pick(e.target.files?.[0])}
            />
            <p className="text-small text-muted">
              {progress !== null
                ? `Uploading… ${Math.round(progress * 100)}%`
                : fileName
                  ? `Chosen: ${fileName}`
                  : 'Up to 500 MB.'}
            </p>
            {errors.file_id ? <p className="text-small text-danger">{errors.file_id[0]}</p> : null}
          </div>
        )}
        <Field label="Description (optional)" value={form.description} onChange={set('description')} />
        <div className="flex flex-wrap justify-between gap-2">
          {material ? (
            <Button variant="ghost" loading={remove.isPending} onClick={() => remove.mutate()}>
              Delete
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button loading={save.isPending} disabled={progress !== null} onClick={() => save.mutate()}>
              Save
            </Button>
          </div>
        </div>
      </div>
    </Dialog>
  );
}
