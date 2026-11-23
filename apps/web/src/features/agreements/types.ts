import type { Tone } from '@/components/ui';

export type AgreementStatus = 'sent' | 'completed' | 'declined' | 'cancelled' | 'expired';
/** Who a template is between. A parent or guardian may sign a learner agreement as well. */
export type Role = 'learner' | 'employer' | 'provider';
export type SignerRole = Role | 'guardian';

export const ROLE_LABEL: Record<SignerRole, string> = {
  learner: 'Learner',
  employer: 'Host employer',
  provider: 'Training provider',
  guardian: 'Parent or guardian',
};

export const KIND_LABEL: Record<string, string> = {
  workplace: 'Workplace learning',
  employer_mou: 'Employer MoU',
  learner: 'Learner enrolment',
};

export const agreementState: Record<AgreementStatus, { label: string; tone: Tone }> = {
  sent: { label: 'Waiting for signatures', tone: 'warning' },
  completed: { label: 'Signed', tone: 'success' },
  declined: { label: 'Declined', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
  expired: { label: 'Expired', tone: 'neutral' },
};

export const signerState: Record<string, { label: string; tone: Tone }> = {
  pending: { label: 'Waiting', tone: 'warning' },
  signed: { label: 'Signed', tone: 'success' },
  declined: { label: 'Declined', tone: 'danger' },
};

export interface AgreementRow {
  id: string;
  title: string;
  kind: string;
  status: AgreementStatus;
  signature_level: 'simple' | 'advanced';
  employer_id: string | null;
  verify_code: string;
  sent_at: string | null;
  completed_at: string | null;
  expires_at: string | null;
  has_document: boolean;
  signers: { role: SignerRole; name: string; status: string }[];
}

export interface AgreementDetail extends Omit<AgreementRow, 'signers'> {
  body: string;
  text_sha256: string;
  document_sha256: string | null;
  verify_url: string;
  template: { id: string; name: string; version: number } | null;
  signers: {
    id: string;
    role: SignerRole;
    name: string;
    status: string;
    contact: string | null;
    signed_at: string | null;
    decline_reason: string | null;
    reminders_sent: number;
    evidence: Record<string, string> | null;
  }[];
}

export interface Template {
  id: string;
  family_id: string;
  version: number;
  kind: string;
  name: string;
  body: string;
  parties: Role[];
  advanced_signature: boolean;
  status: 'draft' | 'published' | 'retired';
  published_at: string | null;
  updated_at: string;
}

export interface TemplateFamily extends Template {
  published_version: number | null;
  published_id: string | null;
  versions: { id: string; version: number; status: string; published_at: string | null }[];
}

export const when = (iso: string | null) =>
  iso
    ? new Date(iso).toLocaleString('en-ZA', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : '—';
