export interface IntegrationField {
  name: string;
  label: string;
  secret: boolean;
  required: boolean;
  type: 'text' | 'url' | 'number' | 'bool' | 'select';
  options: string[] | null;
  default: string | number | boolean | null;
  help: string | null;
}

export interface Integration {
  id: string;
  type: string;
  driver: string;
  mode: 'sandbox' | 'live';
  enabled: boolean;
  priority: number;
  values: Record<string, string | number | boolean | null>;
  secrets: Record<string, string | null>;
  complete: boolean;
  testable: boolean;
  last_test: { at: string; ok: boolean; message: string } | null;
  updated_at: string;
}

export interface Driver {
  driver: string;
  label: string;
  description: string;
  capabilities: string[];
  note: string | null;
  fields: IntegrationField[];
  integration: Integration | null;
}

export interface IntegrationType {
  type: 'payment' | 'sms' | 'whatsapp' | 'email' | 'storage';
  label: string;
  single: boolean;
  modes: boolean;
  drivers: Driver[];
}

export const capabilityLabels: Record<string, string> = {
  card: 'Cards',
  recurring: 'Subscriptions',
  instant_eft: 'Instant EFT',
  send: 'Send',
  delivery_receipts: 'Delivery receipts',
  templates: 'Templates',
  authentication: 'Sign-in codes',
  store: 'Store files',
  direct_upload: 'Direct uploads',
};
