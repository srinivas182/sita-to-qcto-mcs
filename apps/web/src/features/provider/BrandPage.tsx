import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Alert, Button, Card, CardHeader, Field, PageHeader, useToast } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { brandingQuery, meQuery, useMe } from '@/lib/session';
import { DomainsPanel } from './DomainsPanel';
import { LogoPicker } from './LogoPicker';

/** Brand & domain: logo, colours and tagline with a live sign-in preview, plus domains. */
export function BrandPage() {
  const me = useMe();
  const qc = useQueryClient();
  const toast = useToast();
  const current = (me.tenant?.branding ?? {}) as Record<string, string | undefined>;
  const [form, setForm] = useState({
    trading_name: me.tenant?.name ?? '',
    logo_url: current.logo_url ?? '',
    // undefined: unchanged; null: remove; string: a newly uploaded file.
    logo_file_id: undefined as string | null | undefined,
    primary: current.primary ?? '#4338ca',
    tagline: current.tagline ?? '',
  });
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () =>
      call('PATCH', '/tenant/branding', {
        trading_name: form.trading_name,
        branding: {
          ...(form.logo_file_id !== undefined ? { logo_file_id: form.logo_file_id } : {}),
          primary: form.primary,
          tagline: form.tagline || null,
        },
      }),
    onSuccess: () => {
      toast('Brand updated');
      void qc.invalidateQueries({ queryKey: meQuery.queryKey });
      void qc.invalidateQueries({ queryKey: brandingQuery.queryKey });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    save.mutate();
  };

  return (
    <>
      <PageHeader
        eyebrow={me.tenant?.name}
        title="Brand & domain"
        description="Learners and staff see your name, logo and colours on every sign-in screen and in the app."
      />
      <div className="grid gap-5 xl:grid-cols-2">
        <Card>
          <CardHeader title="Brand" />
          <form onSubmit={submit} className="grid gap-4">
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <Field
              label="Display name"
              value={form.trading_name}
              onChange={(e) => setForm({ ...form, trading_name: e.target.value })}
            />
            <LogoPicker
              preview={form.logo_url || null}
              onUploaded={(id, url) => setForm({ ...form, logo_file_id: id, logo_url: url })}
              onClear={() => setForm({ ...form, logo_file_id: null, logo_url: '' })}
            />
            <div className="grid gap-1.5">
              <label className="text-small font-medium" htmlFor="primary">
                Brand colour
              </label>
              <div className="flex items-center gap-3">
                <input
                  id="primary"
                  type="color"
                  value={form.primary}
                  onChange={(e) => setForm({ ...form, primary: e.target.value })}
                  className="h-10 w-14 cursor-pointer rounded-md border border-line"
                />
                <code className="text-small">{form.primary}</code>
              </div>
            </div>
            <Field
              label="Tagline"
              maxLength={120}
              value={form.tagline}
              onChange={(e) => setForm({ ...form, tagline: e.target.value })}
              placeholder="Building South Africa's future artisans"
            />
            <div>
              <Button type="submit" loading={save.isPending}>
                Save brand
              </Button>
            </div>
          </form>
        </Card>

        <Card className="overflow-hidden p-0">
          <div className="border-b border-line px-5 py-3 text-small text-muted">Sign-in preview</div>
          <div className="grid grid-cols-2">
            <div
              className="flex min-h-64 flex-col justify-between p-6 text-white"
              style={{ background: `linear-gradient(160deg, ${form.primary}, #1e1b4b 75%)` }}
            >
              <div className="flex items-center gap-2">
                {form.logo_url ? (
                  <img src={form.logo_url} alt="" className="size-8 rounded-lg bg-white object-contain p-0.5" />
                ) : (
                  <span className="grid size-8 place-items-center rounded-lg bg-white/15 font-bold">
                    {form.trading_name.charAt(0)}
                  </span>
                )}
                <span className="text-small font-semibold">{form.trading_name}</span>
              </div>
              <p className="text-[15px] font-semibold leading-snug">
                {form.tagline || 'QCTO-ready occupational training, in one place'}
              </p>
            </div>
            <div className="grid content-center gap-2 p-6">
              <div className="h-3 w-3/4 rounded bg-line" />
              <div className="h-2 w-1/2 rounded bg-line-soft" />
              <div className="mt-3 h-8 rounded-md" style={{ background: form.primary }} />
              <div className="h-8 rounded-md border border-line" />
            </div>
          </div>
        </Card>

        <div className="xl:col-span-2">
          <DomainsPanel basePath={`/tenants/${me.tenant?.id}/domains`} />
        </div>
      </div>
    </>
  );
}
