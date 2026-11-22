import { useQuery } from '@tanstack/react-query';
import { BadgeCheck, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { brandingQuery, type Branding } from '@/lib/session';
import { useI18n, locales, type Locale } from '@/lib/i18n';

/** Branded split layout for every sign-in screen (provider logo, colour and name). */
export function AuthLayout({ children }: { children: ReactNode }) {
  const { data: brand } = useQuery(brandingQuery);
  const { t, locale, setLocale } = useI18n();
  const name = brand?.name ?? t('app.name');
  const primary = brand?.branding.primary;

  return (
    <div className="grid min-h-dvh lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
      <aside
        className="relative hidden overflow-hidden bg-primary-950 p-12 text-white lg:flex lg:flex-col"
        style={primary ? { background: `linear-gradient(160deg, ${primary}, #1e1b4b 70%)` } : undefined}
      >
        <div className="pointer-events-none absolute -top-40 -right-40 size-[520px] rounded-full bg-white/[.06] blur-3xl" />
        <Logo name={name} logo={brand?.branding.logo_url} light />
        {brand?.verified ? <VerifiedBadge verified={brand.verified} light /> : null}
        <div className="mt-auto max-w-md">
          <h2 className="text-[30px] leading-tight font-semibold tracking-tight">
            {brand?.branding.tagline ?? t('app.tagline')}
          </h2>
          <ul className="mt-6 grid gap-3 text-[14px] text-white/80">
            <li className="flex items-center gap-2.5">
              <ShieldCheck className="size-4 text-primary-200" /> Passkeys, two-step verification and encrypted records
            </li>
            <li className="flex items-center gap-2.5">
              <ShieldCheck className="size-4 text-primary-200" /> Every action recorded for QCTO and SETA audits
            </li>
            <li className="flex items-center gap-2.5">
              <ShieldCheck className="size-4 text-primary-200" /> POPIA-compliant consent and data handling
            </li>
          </ul>
        </div>
        <p className="mt-10 text-small text-white/50">TYM × MCS Joint Venture</p>
      </aside>

      <main className="flex flex-col px-5 py-8 sm:px-10">
        <div className="flex items-center justify-between lg:justify-end">
          <div className="grid gap-2 lg:hidden">
            <Logo name={name} logo={brand?.branding.logo_url} />
            {brand?.verified ? <VerifiedBadge verified={brand.verified} /> : null}
          </div>
          <select
            aria-label="Language"
            value={locale}
            onChange={(e) => setLocale(e.target.value as Locale)}
            className="h-9 rounded-md border border-line bg-surface px-2 text-small text-muted"
          >
            {Object.entries(locales).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </div>
        <div className="mx-auto my-auto w-full max-w-[400px] animate-in py-10">{children}</div>
      </main>
    </div>
  );
}

/** Learners and employers can see the provider's accreditation was checked. */
function VerifiedBadge({ verified, light }: { verified: NonNullable<Branding['verified']>; light?: boolean }) {
  const until = verified.valid_until
    ? new Date(verified.valid_until).toLocaleDateString('en-ZA', { month: 'short', year: 'numeric' })
    : null;
  return (
    <p
      className={`mt-4 inline-flex w-fit items-center gap-1.5 rounded-full px-3 py-1 text-small font-medium lg:mt-6 ${light ? 'bg-white/10 text-white ring-1 ring-white/20' : 'bg-success-bg text-success'}`}
      title={verified.accreditation_number ? `QCTO accreditation ${verified.accreditation_number}` : undefined}
    >
      <BadgeCheck className="size-4" aria-hidden />
      Verified QCTO-accredited provider{until ? ` · valid to ${until}` : ''}
    </p>
  );
}

export function Logo({ name, logo, light }: { name: string; logo?: string | undefined; light?: boolean }) {
  return (
    <div className="flex items-center gap-3">
      {logo ? (
        <img src={logo} alt="" className="size-10 rounded-xl bg-white object-contain p-1" />
      ) : (
        <span
          className={`grid size-10 place-items-center rounded-xl text-[17px] font-bold ${light ? 'bg-white/10 text-white ring-1 ring-white/20' : 'bg-primary-700 text-white'}`}
        >
          {name.charAt(0)}
        </span>
      )}
      <span className={`text-[15px] font-semibold ${light ? 'text-white' : 'text-ink'}`}>{name}</span>
    </div>
  );
}
