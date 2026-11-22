import { useMe } from '@/lib/session';
import { PlatformOverviewPage } from '@/features/admin/OverviewPage';
import { ProviderOverviewPage } from '@/features/provider/ProviderOverviewPage';

export function Home() {
  const me = useMe();
  return me.tenant ? <ProviderOverviewPage /> : <PlatformOverviewPage />;
}
