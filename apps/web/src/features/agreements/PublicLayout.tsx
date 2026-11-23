import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { brandingQuery } from '@/lib/session';
import { Logo } from '@/features/auth/AuthLayout';

/** A plain, readable page under the provider's name: for signing and checking documents. */
export function PublicLayout({ children }: { children: ReactNode }) {
  const { data: brand } = useQuery(brandingQuery);
  return (
    <div className="min-h-dvh bg-canvas">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex max-w-3xl items-center px-4 py-3">
          <Logo name={brand?.name ?? 'Training provider'} logo={brand?.branding.logo_url} />
        </div>
      </header>
      <main className="mx-auto grid max-w-3xl gap-4 px-4 py-6">{children}</main>
    </div>
  );
}
