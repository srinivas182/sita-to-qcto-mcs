import {
  Building2,
  Gauge,
  LifeBuoy,
  Palette,
  ScrollText,
  Server,
  ShieldCheck,
  UserCog,
  Users,
  type LucideIcon,
} from 'lucide-react';
import type { MessageKey } from '@/lib/i18n/en';
import type { Me } from '@/lib/api';

export interface NavItem {
  to: string;
  label: MessageKey;
  icon: LucideIcon;
  permission?: string;
  group: 'main' | 'manage' | 'account';
}

const platform: NavItem[] = [
  { to: '/', label: 'nav.overview', icon: Gauge, group: 'main', permission: 'platform.tenants.view' },
  { to: '/tenants', label: 'nav.tenants', icon: Building2, group: 'main', permission: 'platform.tenants.view' },
  { to: '/cells', label: 'nav.cells', icon: Server, group: 'main', permission: 'platform.cells.view' },
  { to: '/support', label: 'nav.support', icon: LifeBuoy, group: 'manage', permission: 'platform.support.request' },
  { to: '/staff', label: 'nav.staff', icon: UserCog, group: 'manage', permission: 'platform.users.manage' },
  { to: '/audit', label: 'nav.audit', icon: ScrollText, group: 'manage', permission: 'platform.audit.view' },
];

const provider: NavItem[] = [
  { to: '/', label: 'nav.overview', icon: Gauge, group: 'main' },
  { to: '/users', label: 'nav.users', icon: Users, group: 'main', permission: 'tenant.users.view' },
  { to: '/brand', label: 'nav.brand', icon: Palette, group: 'manage', permission: 'tenant.settings.manage' },
  { to: '/support', label: 'nav.support', icon: LifeBuoy, group: 'manage', permission: 'tenant.support.approve' },
  { to: '/audit', label: 'nav.audit', icon: ScrollText, group: 'manage', permission: 'tenant.audit.view' },
];

const account: NavItem[] = [{ to: '/security', label: 'nav.security', icon: ShieldCheck, group: 'account' }];

/** Navigation for where the person is (platform console or a provider) and what they may do. */
export function navFor(me: Me): NavItem[] {
  const items = me.tenant ? provider : platform;
  return [...items.filter((i) => !i.permission || me.permissions.includes(i.permission)), ...account];
}
