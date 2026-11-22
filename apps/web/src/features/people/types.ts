export interface Credential {
  id: string;
  user_id: string;
  role: string;
  role_label: string;
  body: string;
  registration_number: string;
  qualifications: { id: string; title: string | null }[];
  starts_on: string | null;
  expires_on: string | null;
  file_id: string | null;
  status: 'pending' | 'verified' | 'rejected';
  state: 'ok' | 'expiring' | 'expired' | 'pending' | 'rejected';
  verified_at: string | null;
  notes: string | null;
  person?: string;
}

export const credentialState: Record<
  Credential['state'],
  { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; label: string }
> = {
  ok: { tone: 'success', label: 'Verified' },
  expiring: { tone: 'warning', label: 'Expiring soon' },
  expired: { tone: 'danger', label: 'Expired' },
  pending: { tone: 'info', label: 'Waiting for checking' },
  rejected: { tone: 'danger', label: 'Not accepted' },
};

export const ROLES: Record<string, string> = {
  facilitator: 'Facilitator',
  assessor: 'Assessor',
  moderator: 'Moderator',
  rpl_advisor: 'RPL advisor',
  mentor: 'Workplace mentor',
  subject_expert: 'Subject matter expert',
};

export const BODIES = [
  'QCTO',
  'CETA',
  'MERSETA',
  'ETDP SETA',
  'SERVICES SETA',
  'EWSETA',
  'MICT SETA',
  'NAMB',
  'SACE',
  'ECSA',
];
