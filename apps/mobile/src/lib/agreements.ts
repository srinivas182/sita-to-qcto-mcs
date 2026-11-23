import { useQuery } from '@tanstack/react-query';
import { api, json } from './api';

export interface MyAgreementRow {
  id: string;
  title: string;
  role: string;
  status: 'pending' | 'signed' | 'declined';
  agreement_status: string;
  sent_at: string | null;
  signed_at: string | null;
  can_sign: boolean;
}

export interface MyAgreement {
  id: string;
  title: string;
  provider: string;
  body: string;
  text_sha256: string;
  agreement_status: string;
  expires_at: string | null;
  you: { name: string; role: string; status: string; pin_to: string | null; signed_at: string | null };
  parties: { role: string; name: string; status: string }[];
  can_sign: boolean;
  verify_url: string;
}

/** Documents waiting for, or signed by, the person on this phone. */
export function useMyAgreements(enabled = true) {
  return useQuery({
    queryKey: ['my-agreements'],
    queryFn: () => json<{ data: MyAgreementRow[] }>(api.GET('/me/agreements' as never)).then((r) => r.data),
    enabled,
  });
}

export const CHANNEL: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'email' };
