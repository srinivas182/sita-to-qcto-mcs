import { AlertTriangle, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { cx } from './cx';

export function Alert({
  tone = 'info',
  children,
  className,
}: {
  tone?: 'info' | 'danger' | 'warning' | 'success';
  children: ReactNode;
  className?: string;
}) {
  const styles = {
    info: 'bg-info-bg text-info',
    danger: 'bg-danger-bg text-danger',
    warning: 'bg-warning-bg text-warning',
    success: 'bg-success-bg text-success',
  }[tone];
  const Icon = tone === 'info' || tone === 'success' ? Info : AlertTriangle;

  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={cx('flex items-start gap-2.5 rounded-lg px-3.5 py-3 text-small', styles, className)}
    >
      <Icon className="mt-0.5 size-4 flex-none" />
      <div className="min-w-0">{children}</div>
    </div>
  );
}
