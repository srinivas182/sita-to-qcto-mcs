import { useQuery } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, Card, Skeleton } from '@/components/ui';
import { call } from '@/lib/api';
import { PublicLayout } from './PublicLayout';
import { agreementState, ROLE_LABEL, signerState, when, type AgreementStatus, type SignerRole } from './types';

interface Verification {
  title: string;
  provider: string;
  status: AgreementStatus;
  signature_level: string;
  sent_at: string | null;
  completed_at: string | null;
  text_sha256: string;
  document_sha256: string | null;
  signers: { role: SignerRole; name: string; status: string; signed_at: string | null }[];
}

async function sha256(file: File): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Public: who signed a document and when, and whether a copy is unchanged (checked in the browser). */
export function VerifyPage() {
  const { code } = useParams({ strict: false }) as { code: string };
  const q = useQuery({
    queryKey: ['verify', code],
    queryFn: () => call<{ data: Verification }>('GET', `/verify/${code}`).then((r) => r.data),
    retry: false,
  });
  const [check, setCheck] = useState<{ name: string; ok: boolean } | null>(null);

  if (q.isPending)
    return (
      <PublicLayout>
        <Skeleton className="h-48" />
      </PublicLayout>
    );
  if (q.isError)
    return (
      <PublicLayout>
        <Card>
          <h1 className="text-[20px] font-semibold">We can't find this document</h1>
          <p className="mt-2 text-muted">Check the code or link printed on the document.</p>
        </Card>
      </PublicLayout>
    );
  const v = q.data;

  return (
    <PublicLayout>
      <div>
        <p className="text-small text-muted">Document check · {v.provider}</p>
        <h1 className="text-[22px] font-semibold tracking-tight text-ink">{v.title}</h1>
        <div className="mt-2">
          <Badge tone={agreementState[v.status].tone} dot>
            {agreementState[v.status].label}
          </Badge>
        </div>
      </div>
      <Card>
        <h2 className="mb-3 font-semibold text-ink">Signatures</h2>
        <ul className="divide-y divide-line">
          {v.signers.map((s) => (
            <li key={s.role} className="flex items-center justify-between gap-3 py-2.5">
              <div>
                <div className="font-medium text-ink">{s.name}</div>
                <div className="text-small text-muted">{ROLE_LABEL[s.role]}</div>
              </div>
              <div className="text-right">
                <Badge tone={signerState[s.status]?.tone ?? 'neutral'}>
                  {signerState[s.status]?.label ?? s.status}
                </Badge>
                {s.signed_at ? <div className="mt-1 text-small text-muted">{when(s.signed_at)}</div> : null}
              </div>
            </li>
          ))}
        </ul>
        <p className="mt-3 text-small text-muted">
          {v.signature_level === 'advanced'
            ? 'Signed with advanced electronic signatures through LAWtrust.'
            : 'Each signature was confirmed with a one-time PIN sent to the signer.'}
        </p>
      </Card>
      {v.document_sha256 ? (
        <Card>
          <h2 className="mb-1 font-semibold text-ink">Check a copy</h2>
          <p className="mb-3 text-small text-muted">
            Choose the PDF you were given. It is checked on this device and is not uploaded.
          </p>
          <input
            type="file"
            accept="application/pdf"
            aria-label="Choose the signed PDF"
            onChange={async (e) => {
              const file = e.target.files?.[0];
              if (file) setCheck({ name: file.name, ok: (await sha256(file)) === v.document_sha256 });
            }}
          />
          {check ? (
            <div className="mt-3">
              {check.ok ? (
                <Alert tone="success">
                  <CheckCircle2 className="mr-1 inline size-4 align-text-bottom" />
                  {check.name} is exactly the signed document.
                </Alert>
              ) : (
                <Alert tone="danger">
                  <XCircle className="mr-1 inline size-4 align-text-bottom" />
                  {check.name} is not the signed document: it has been changed, or it is a different file.
                </Alert>
              )}
            </div>
          ) : null}
          <p className="mt-3 font-mono text-[11px] break-all text-faint">{v.document_sha256}</p>
        </Card>
      ) : null}
    </PublicLayout>
  );
}
