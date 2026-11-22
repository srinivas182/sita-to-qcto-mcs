import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, Eye, EyeOff, FlaskConical, Send, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert, Button, Dialog, Field, Select, useToast } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { integrationsKey } from './IntegrationsTab';
import type { Driver, Integration, IntegrationField, IntegrationType } from './types';

type Values = Record<string, string | boolean>;

function initialValues(driver: Driver): Values {
  const out: Values = {};
  for (const f of driver.fields) {
    if (f.secret) {
      out[f.name] = '';
      continue;
    }
    const v = driver.integration?.values[f.name] ?? f.default;
    out[f.name] = f.type === 'bool' ? Boolean(v) : v == null ? '' : String(v);
  }
  return out;
}

/** Set up or change one integration. Secrets are write-only: blank keeps the saved key. */
export function IntegrationDialog({
  group,
  driver,
  onClose,
}: {
  group: IntegrationType;
  driver: Driver;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const saved = driver.integration;
  const [values, setValues] = useState<Values>(() => initialValues(driver));
  const [mode, setMode] = useState(saved?.mode ?? 'sandbox');
  const [enabled, setEnabled] = useState(saved?.enabled ?? false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ ok: boolean; message: string } | null>(null);
  const [shown, setShown] = useState<Record<string, boolean>>({});
  const [confirmRemove, setConfirmRemove] = useState(false);

  const refresh = () => void qc.invalidateQueries({ queryKey: integrationsKey });
  const fail = (e: unknown) => {
    setError(errorMessage(e));
    if (e instanceof ApiError) setErrors(e.fields);
  };

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, string | boolean | null> = {};
      for (const f of driver.fields) {
        const v = values[f.name];
        // Untouched secrets are left out, so the saved key is kept.
        if (f.secret && v === '') continue;
        payload[f.name] = v === '' ? null : (v ?? null);
      }
      return call<{ data: Integration }>('PUT', `/admin/settings/integrations/${driver.driver}`, {
        values: payload,
        ...(group.modes ? { mode } : {}),
        enabled,
      });
    },
    onSuccess: () => {
      toast(`${driver.label} saved`);
      refresh();
      onClose();
    },
    onError: (e) => {
      fail(e);
      refresh();
    },
  });

  const test = useMutation({
    mutationFn: () => call<{ ok: boolean; message: string }>('POST', `/admin/settings/integrations/${saved!.id}/test`),
    onSuccess: (r) => {
      setNotice(r);
      refresh();
    },
    onError: fail,
  });

  const remove = useMutation({
    mutationFn: () => call('DELETE', `/admin/settings/integrations/${saved!.id}`),
    onSuccess: () => {
      toast(`${driver.label} removed`);
      refresh();
      onClose();
    },
    onError: fail,
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrors({});
    setNotice(null);
    save.mutate();
  };

  const input = (f: IntegrationField) => {
    const err = errors[`values.${f.name}`]?.[0];
    if (f.type === 'bool') {
      return (
        <label key={f.name} className="flex items-center gap-2 text-body">
          <input
            type="checkbox"
            className="size-4 accent-primary-700"
            checked={Boolean(values[f.name])}
            onChange={(e) => setValues({ ...values, [f.name]: e.target.checked })}
          />
          {f.label}
        </label>
      );
    }
    if (f.type === 'select') {
      return (
        <Select
          key={f.name}
          label={f.label}
          value={String(values[f.name] ?? '')}
          onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
        >
          {(f.options ?? []).map((o) => (
            <option key={o} value={o}>
              {o.toUpperCase()}
            </option>
          ))}
        </Select>
      );
    }
    const hint =
      f.secret && saved?.secrets[f.name] ? `Saved (${saved.secrets[f.name]}). Leave blank to keep it.` : f.help;
    return (
      <Field
        key={f.name}
        label={f.label + (f.required ? '' : ' (optional)')}
        type={
          f.secret && !shown[f.name] ? 'password' : f.type === 'number' ? 'number' : f.type === 'url' ? 'url' : 'text'
        }
        autoComplete={f.secret ? 'new-password' : 'off'}
        spellCheck={false}
        required={f.required && !(f.secret && saved?.secrets[f.name]) && enabled}
        value={String(values[f.name] ?? '')}
        onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
        error={err}
        hint={hint ?? undefined}
        trailing={
          f.secret ? (
            <button
              type="button"
              className="text-caption text-muted hover:text-ink"
              onClick={() => setShown({ ...shown, [f.name]: !shown[f.name] })}
              aria-label={shown[f.name] ? `Hide ${f.label}` : `Show ${f.label}`}
            >
              {shown[f.name] ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
          ) : undefined
        }
      />
    );
  };

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={driver.label} description={driver.description}>
      <form onSubmit={submit} className="grid gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {notice ? <Alert tone={notice.ok ? 'success' : 'danger'}>{notice.message}</Alert> : null}
        {driver.note ? <Alert tone="info">{driver.note}</Alert> : null}
        {saved?.callback_url ? <CallbackUrl url={saved.callback_url} /> : null}
        {group.modes ? (
          <Select label="Account" value={mode} onChange={(e) => setMode(e.target.value as 'sandbox' | 'live')}>
            <option value="sandbox">Sandbox (test keys, no real money)</option>
            <option value="live">Live</option>
          </Select>
        ) : null}
        {driver.fields.map(input)}
        <label className="flex items-start gap-2 rounded-lg border border-line p-3 text-body">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-primary-700"
            checked={enabled}
            onChange={(e) => setEnabled(e.target.checked)}
          />
          <span>
            <span className="font-medium">Switched on</span>
            <span className="block text-small text-muted">
              {group.single
                ? 'Turning this on turns the other storage off. New files go here; existing files stay where they are.'
                : 'Used by the platform, in the order shown on the list.'}
              {(saved?.testable ?? group.type === 'storage') ? ' A connection test runs first.' : ''}
            </span>
          </span>
        </label>
        {saved && (group.type === 'sms' || group.type === 'whatsapp') ? <SendTest integrationId={saved.id} /> : null}
        <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
          <div className="flex gap-2">
            {saved?.testable ? (
              <Button
                type="button"
                variant="secondary"
                size="sm"
                icon={<FlaskConical className="size-4" />}
                loading={test.isPending}
                onClick={() => test.mutate()}
              >
                Test saved settings
              </Button>
            ) : null}
            {saved ? (
              <Button
                type="button"
                variant={confirmRemove ? 'danger' : 'ghost'}
                size="sm"
                icon={<Trash2 className="size-4" />}
                loading={remove.isPending}
                onClick={() => (confirmRemove ? remove.mutate() : setConfirmRemove(true))}
              >
                {confirmRemove ? 'Remove keys?' : 'Remove'}
              </Button>
            ) : null}
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={save.isPending}>
              Save
            </Button>
          </div>
        </div>
      </form>
    </Dialog>
  );
}

/** The address the provider must call back, with a copy button. */
function CallbackUrl({ url }: { url: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="grid gap-1.5">
      <span className="text-small font-medium text-ink">Callback URL (paste into the provider&apos;s dashboard)</span>
      <div className="flex items-center gap-2">
        <code
          className="min-w-0 flex-1 truncate rounded-lg border border-line bg-line-soft px-3 py-2 text-caption"
          title={url}
        >
          {url}
        </code>
        <Button
          type="button"
          size="sm"
          variant="secondary"
          icon={copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          onClick={() => {
            void navigator.clipboard?.writeText(url);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
          }}
        >
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
    </div>
  );
}

/** Send one real message through this provider to a phone the admin chooses. */
function SendTest({ integrationId }: { integrationId: string }) {
  const [to, setTo] = useState('');
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
  const send = useMutation({
    mutationFn: () =>
      call<{ sent: boolean; error: string | null }>('POST', `/admin/settings/integrations/${integrationId}/send-test`, {
        to,
      }),
    onSuccess: (r) =>
      setResult(r.sent ? { ok: true, text: 'Sent. Check the phone.' } : { ok: false, text: r.error ?? 'Not sent.' }),
    onError: (e) => setResult({ ok: false, text: errorMessage(e) }),
  });
  return (
    <div className="grid gap-2 rounded-lg border border-line p-3">
      <div className="flex items-end gap-2">
        <Field
          label="Send a test message to"
          type="tel"
          inputMode="tel"
          placeholder="082 123 4567"
          value={to}
          onChange={(e) => setTo(e.target.value)}
          className="flex-1"
        />
        <Button
          type="button"
          variant="secondary"
          icon={<Send className="size-4" />}
          loading={send.isPending}
          disabled={to.length < 9}
          onClick={() => send.mutate()}
        >
          Send
        </Button>
      </div>
      {result ? (
        <p className={result.ok ? 'text-small text-success' : 'text-small text-danger'} role="status">
          {result.text}
        </p>
      ) : null}
    </div>
  );
}
