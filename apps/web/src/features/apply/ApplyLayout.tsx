import { Link } from '@tanstack/react-router';
import { BadgeCheck, Clock, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { useI18n } from '@/lib/i18n';

/** Public pages on the platform's own site: pricing and applying to join. */
export function ApplyLayout({ children, wide }: { children: ReactNode; wide?: boolean }) {
  const { t } = useI18n();
  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between px-5">
          <Link to="/pricing" className="font-semibold text-ink">
            {t('app.name')}
          </Link>
          <nav className="flex items-center gap-4 text-small">
            <Link to="/pricing" className="text-muted hover:text-ink">
              Pricing
            </Link>
            <Link to="/apply" className="font-medium text-primary-700 hover:underline">
              Apply
            </Link>
            <Link to="/sign-in" className="text-muted hover:text-ink">
              Sign in
            </Link>
          </nav>
        </div>
      </header>
      <main
        className={
          wide
            ? 'mx-auto max-w-5xl px-5 py-10'
            : 'mx-auto grid max-w-5xl gap-10 px-5 py-10 lg:grid-cols-[minmax(0,1fr)_280px]'
        }
      >
        <div className={wide ? '' : 'card p-6 sm:p-8'}>{children}</div>
        {wide ? null : (
          <aside className="grid content-start gap-4 text-small text-muted">
            <p className="flex gap-2">
              <BadgeCheck className="size-4 shrink-0 text-primary-700" aria-hidden /> Have your QCTO accreditation
              letter and CIPC number ready.
            </p>
            <p className="flex gap-2">
              <Clock className="size-4 shrink-0 text-primary-700" aria-hidden /> Reviewed by a person within about 2
              working days.
            </p>
            <p className="flex gap-2">
              <ShieldCheck className="size-4 shrink-0 text-primary-700" aria-hidden /> Your documents are virus-checked
              and only seen by reviewers.
            </p>
          </aside>
        )}
      </main>
    </div>
  );
}
