import type { HTMLAttributes, ReactNode } from 'react';
import { cx } from './cx';

export function Card({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cx('card p-5', className)} {...rest} />;
}

export function CardHeader({
  title,
  subtitle,
  action,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="mb-4 flex items-start justify-between gap-3">
      <div className="min-w-0">
        <h3 className="text-card-title font-semibold text-ink">{title}</h3>
        {subtitle ? <p className="mt-0.5 text-small text-muted">{subtitle}</p> : null}
      </div>
      {action}
    </div>
  );
}

export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
}: {
  eyebrow?: string;
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0 max-w-3xl">
        {eyebrow ? <div className="eyebrow">{eyebrow}</div> : null}
        <h1 className="mt-1 text-page-title font-semibold tracking-tight text-ink">{title}</h1>
        {description ? <p className="mt-1 text-body text-muted">{description}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
    </header>
  );
}

export function Kpi({
  label,
  value,
  icon,
  hint,
}: {
  label: string;
  value: ReactNode;
  icon?: ReactNode;
  hint?: ReactNode;
}) {
  return (
    <div className="card relative overflow-hidden p-5">
      <div className="flex items-center justify-between text-small text-muted">
        <span>{label}</span>
        {icon ? (
          <span className="grid size-8 place-items-center rounded-md bg-primary-50 text-primary-700">{icon}</span>
        ) : null}
      </div>
      <div className="mt-2 text-kpi font-semibold tracking-tight tabular-nums">{value}</div>
      {hint ? <div className="mt-0.5 text-small text-muted">{hint}</div> : null}
    </div>
  );
}

export function EmptyState({ icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="grid place-items-center gap-2 px-6 py-12 text-center">
      {icon ? (
        <div className="grid size-11 place-items-center rounded-xl bg-primary-50 text-primary-700">{icon}</div>
      ) : null}
      <p className="font-medium text-ink">{title}</p>
      {children ? <div className="max-w-sm text-small text-muted">{children}</div> : null}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cx('relative overflow-hidden rounded-md bg-line-soft', className)} aria-hidden />;
}
