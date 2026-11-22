import { lazy, Suspense } from 'react';
import { Skeleton } from '@/components/ui';
import { useMe } from '@/lib/session';

const ProviderBilling = lazy(() => import('@/features/billing/BillingPage').then((m) => ({ default: m.BillingPage })));
const AdminBilling = lazy(() =>
  import('@/features/billing/AdminBillingPage').then((m) => ({ default: m.AdminBillingPage })),
);

/** /billing: the provider's own billing, or the Super Admin's view of all providers. */
export function BillingRoute() {
  const me = useMe();
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>{me.tenant ? <ProviderBilling /> : <AdminBilling />}</Suspense>
  );
}
