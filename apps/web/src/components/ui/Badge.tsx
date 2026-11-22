import type { ReactNode } from 'react';
import { cx } from './cx';

export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary';

const tones: Record<Tone, string> = {
  neutral: 'bg-line-soft text-muted',
  success: 'bg-success-bg text-success',
  warning: 'bg-warning-bg text-warning',
  danger: 'bg-danger-bg text-danger',
  info: 'bg-info-bg text-info',
  primary: 'bg-primary-50 text-primary-700',
};

export function Badge({ tone = 'neutral', children, dot }: { tone?: Tone; children: ReactNode; dot?: boolean }) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-caption font-semibold first-letter:uppercase',
        tones[tone],
      )}
    >
      {dot ? <span className="size-1.5 rounded-full bg-current" /> : null}
      {children}
    </span>
  );
}

const statusTone: Record<string, Tone> = {
  active: 'success',
  verified: 'success',
  approved: 'success',
  pending: 'warning',
  issuing: 'info',
  suspended: 'danger',
  failed: 'danger',
  denied: 'danger',
  revoked: 'neutral',
  expired: 'neutral',
  draining: 'warning',
  full: 'warning',
  submitted: 'info',
  info_requested: 'warning',
  declined: 'danger',
  draft: 'neutral',
};

export function StatusBadge({ status }: { status: string }) {
  return (
    <Badge tone={statusTone[status] ?? 'neutral'} dot>
      {status.replace(/_/g, ' ')}
    </Badge>
  );
}
