import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Circle, Copy, Globe, RefreshCw, Star, Trash2, XCircle } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  EmptyState,
  Field,
  Skeleton,
  StatusBadge,
  useToast,
} from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

interface DnsRecord {
  type: string;
  name: string;
  expected: string;
  found: string[];
  ok: boolean;
}
interface Domain {
  id: string;
  hostname: string;
  type: 'subdomain' | 'custom';
  dns_status: string;
  ssl_status: string;
  is_primary: boolean;
  last_checked_at: string | null;
  records: DnsRecord[];
}

/**
 * Domain set-up on one screen: add a domain, copy the two DNS records, press
 * "Check now" and watch DNS and the certificate turn green. Used by TP owners and by
 * the Super Admin (different base path, same component).
 */
export function DomainsPanel({ basePath }: { basePath: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const key = ['domains', basePath];
  const domains = useQuery({
    queryKey: key,
    queryFn: () => call<{ data: Domain[] }>('GET', basePath).then((r) => r.data),
  });
  const [hostname, setHostname] = useState('');
  const [error, setError] = useState<string | null>(null);

  const add = useMutation({
    mutationFn: (h: string) => call('POST', basePath, { hostname: h }),
    onSuccess: () => {
      setHostname('');
      void qc.invalidateQueries({ queryKey: key });
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const action = useMutation({
    mutationFn: ({ id, verb }: { id: string; verb: 'verify' | 'primary' | 'delete' }) =>
      verb === 'delete' ? call('DELETE', `${basePath}/${id}`) : call('POST', `${basePath}/${id}/${verb}`),
    onSuccess: (_d, v) => {
      void qc.invalidateQueries({ queryKey: key });
      if (v.verb === 'primary') toast('Primary domain updated');
    },
    onError: (e) => toast(errorMessage(e), 'error'),
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    add.mutate(hostname);
  };

  return (
    <Card>
      <CardHeader
        title="Domains"
        subtitle="Your free subdomain works immediately. Connect your own domain with two DNS records; the certificate is issued automatically."
      />
      <form onSubmit={submit} className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
        <Field
          label="Add your own domain"
          placeholder="learn.yourcompany.co.za"
          value={hostname}
          onChange={(e) => setHostname(e.target.value)}
          error={error}
          className="flex-1"
        />
        <Button type="submit" loading={add.isPending} icon={<Globe className="size-4" />}>
          Connect domain
        </Button>
      </form>

      {domains.isPending ? (
        <div className="grid gap-3">
          <Skeleton className="h-16" />
          <Skeleton className="h-16" />
        </div>
      ) : !domains.data?.length ? (
        <EmptyState icon={<Globe className="size-5" />} title="No domains yet" />
      ) : (
        <ul className="grid gap-3">
          {domains.data.map((d) => (
            <li key={d.id} className="rounded-xl border border-line p-4">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{d.hostname}</span>
                {d.is_primary ? <Badge tone="primary">Primary</Badge> : null}
                <Badge>{d.type === 'subdomain' ? 'Platform subdomain' : 'Own domain'}</Badge>
                <span className="ml-auto flex items-center gap-2 text-small text-muted">
                  DNS <StatusBadge status={d.dns_status} /> SSL <StatusBadge status={d.ssl_status} />
                </span>
              </div>

              {d.type === 'custom' && !(d.dns_status === 'verified' && d.ssl_status === 'active') ? (
                <div className="mt-4 grid gap-2">
                  <p className="text-small text-muted">
                    Add these records at your DNS provider, then press “Check now”. Changes can take a few minutes.
                  </p>
                  {d.records.map((r) => (
                    <RecordRow key={r.type} record={r} />
                  ))}
                </div>
              ) : null}

              <div className="mt-3 flex flex-wrap gap-2">
                {d.type === 'custom' ? (
                  <Button
                    size="sm"
                    variant="secondary"
                    icon={<RefreshCw className="size-3.5" />}
                    loading={action.isPending && action.variables?.id === d.id && action.variables.verb === 'verify'}
                    onClick={() => action.mutate({ id: d.id, verb: 'verify' })}
                  >
                    Check now
                  </Button>
                ) : null}
                {!d.is_primary && d.dns_status === 'verified' && d.ssl_status === 'active' ? (
                  <Button
                    size="sm"
                    variant="secondary"
                    icon={<Star className="size-3.5" />}
                    onClick={() => action.mutate({ id: d.id, verb: 'primary' })}
                  >
                    Make primary
                  </Button>
                ) : null}
                {d.type === 'custom' && !d.is_primary ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    icon={<Trash2 className="size-3.5" />}
                    onClick={() => confirm(`Remove ${d.hostname}?`) && action.mutate({ id: d.id, verb: 'delete' })}
                  >
                    Remove
                  </Button>
                ) : null}
                {d.last_checked_at ? (
                  <span className="ml-auto self-center text-caption text-faint">
                    Checked {new Date(d.last_checked_at).toLocaleString()}
                  </span>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
      {add.isError ? null : null}
    </Card>
  );
}

function RecordRow({ record }: { record: DnsRecord }) {
  const [copied, setCopied] = useState(false);
  const checked = record.found.length > 0 || record.ok;

  return (
    <div className="grid gap-2 rounded-lg bg-surface-muted p-3 text-small sm:grid-cols-[auto_1fr_1fr_auto] sm:items-center">
      <span className="flex items-center gap-2 font-semibold">
        {record.ok ? (
          <CheckCircle2 className="size-4 text-success" />
        ) : checked ? (
          <XCircle className="size-4 text-danger" />
        ) : (
          <Circle className="size-4 text-faint" />
        )}
        {record.type}
      </span>
      <code className="truncate rounded bg-surface px-2 py-1 font-mono text-[12px]" title={record.name}>
        {record.name}
      </code>
      <code className="truncate rounded bg-surface px-2 py-1 font-mono text-[12px]" title={record.expected}>
        {record.expected}
      </code>
      <button
        type="button"
        className="flex items-center gap-1 justify-self-start rounded-md px-2 py-1 text-primary-700 hover:bg-primary-50"
        onClick={() => {
          void navigator.clipboard?.writeText(record.expected);
          setCopied(true);
          setTimeout(() => setCopied(false), 1500);
        }}
      >
        <Copy className="size-3.5" /> {copied ? 'Copied' : 'Copy'}
      </button>
      {!record.ok && record.found.length ? (
        <Alert tone="warning" className="sm:col-span-4">
          Found <code>{record.found.join(', ')}</code> — update it to the value above.
        </Alert>
      ) : null}
    </div>
  );
}
