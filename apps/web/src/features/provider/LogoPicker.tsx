import { ImageUp, X } from 'lucide-react';
import { useRef, useState, type DragEvent } from 'react';
import { Button, cx } from '@/components/ui';
import { uploadFile } from '@/lib/upload';

const accepted = ['image/png', 'image/jpeg', 'image/webp'];
const maxBytes = 2 * 1024 * 1024;

/**
 * Logo upload: drop or choose a PNG, JPG or WebP (≤ 2 MB). It is uploaded, scanned
 * and resized at once; the parent saves the returned file id with the brand.
 */
export function LogoPicker({
  preview,
  onUploaded,
  onClear,
}: {
  preview: string | null;
  onUploaded: (fileId: string, previewUrl: string) => void;
  onClear: () => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [over, setOver] = useState(false);

  const take = async (file: File | undefined) => {
    if (!file) return;
    setError(null);
    if (!accepted.includes(file.type)) return setError('Use a PNG, JPG or WebP image.');
    if (file.size > maxBytes) return setError('The logo must be 2 MB or smaller.');

    setProgress(0);
    try {
      const uploaded = await uploadFile('tenant_logo', file, setProgress);
      onUploaded(uploaded.id, `/v1/images/${uploaded.id}/lg`);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The upload failed.');
    } finally {
      setProgress(null);
      if (input.current) input.current.value = '';
    }
  };

  const drop = (e: DragEvent) => {
    e.preventDefault();
    setOver(false);
    void take(e.dataTransfer.files[0]);
  };

  return (
    <div className="grid gap-1.5">
      <span className="text-small font-medium text-ink" id="logo-label">
        Logo
      </span>
      <div
        onDragOver={(e) => {
          e.preventDefault();
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={drop}
        className={cx(
          'flex items-center gap-4 rounded-lg border border-dashed p-3 transition-colors',
          over ? 'border-primary-500 bg-primary-50/60' : 'border-line',
        )}
      >
        <div className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-line-soft">
          {preview ? (
            <img src={preview} alt="Current logo" className="size-full object-contain" />
          ) : (
            <ImageUp className="size-5 text-muted" aria-hidden />
          )}
        </div>
        <div className="min-w-0 flex-1 text-small text-muted">
          {progress !== null ? (
            <div aria-live="polite">
              {progress < 1 ? `Uploading… ${Math.round(progress * 100)}%` : 'Checking the file…'}
              <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-line-soft">
                <div
                  className="h-full rounded-full bg-primary-600 transition-[width]"
                  style={{ width: `${Math.max(5, progress * 100)}%` }}
                />
              </div>
            </div>
          ) : (
            'Drop an image here, or choose one. PNG, JPG or WebP, up to 2 MB. Square logos look best.'
          )}
        </div>
        <div className="flex shrink-0 gap-1">
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => input.current?.click()}
            disabled={progress !== null}
            aria-describedby="logo-label"
          >
            Choose
          </Button>
          {preview ? (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              aria-label="Remove logo"
              onClick={onClear}
              icon={<X className="size-4" />}
            />
          ) : null}
        </div>
        <input
          ref={input}
          type="file"
          accept={accepted.join(',')}
          className="sr-only"
          tabIndex={-1}
          aria-hidden
          onChange={(e) => void take(e.target.files?.[0])}
        />
      </div>
      {error ? (
        <p className="text-small text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
