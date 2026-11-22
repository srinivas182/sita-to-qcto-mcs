import {
  Building2,
  CreditCard,
  Gauge,
  BadgeCheck,
  BookOpen,
  CircleUser,
  GraduationCap,
  Inbox,
  MapPin,
  LifeBuoy,
  MessageSquareText,
  Palette,
  ScrollText,
  Server,
  Settings,
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
  {
    to: '/applications',
    label: 'nav.applications',
    icon: Inbox,
    group: 'main',
    permission: 'platform.tenants.manage',
  },
  { to: '/catalogue', label: 'nav.catalogue', icon: BookOpen, group: 'main', permission: 'platform.catalogue.manage' },
  { to: '/tenants', label: 'nav.tenants', icon: Building2, group: 'main', permission: 'platform.tenants.view' },
  { to: '/cells', label: 'nav.cells', icon: Server, group: 'main', permission: 'platform.cells.view' },
  { to: '/billing', label: 'nav.billing', icon: CreditCard, group: 'main', permission: 'platform.billing.manage' },
  { to: '/support', label: 'nav.support', icon: LifeBuoy, group: 'manage', permission: 'platform.support.request' },
  { to: '/staff', label: 'nav.staff', icon: UserCog, group: 'manage', permission: 'platform.users.manage' },
  { to: '/audit', label: 'nav.audit', icon: ScrollText, group: 'manage', permission: 'platform.audit.view' },
  {
    to: '/messages',
    label: 'nav.messages',
    icon: MessageSquareText,
    group: 'manage',
    permission: 'platform.settings.manage',
  },
  { to: '/settings', label: 'nav.settings', icon: Settings, group: 'manage', permission: 'platform.settings.manage' },
];

const provider: NavItem[] = [
  { to: '/', label: 'nav.overview', icon: Gauge, group: 'main' },
  { to: '/programmes', label: 'nav.programmes', icon: GraduationCap, group: 'main', permission: 'programmes.view' },
  {
    to: '/people/credentials',
    label: 'nav.credentials',
    icon: BadgeCheck,
    group: 'main',
    permission: 'credentials.view',
  },
  { to: '/centres', label: 'nav.centres', icon: MapPin, group: 'main', permission: 'centres.view' },
  { to: '/users', label: 'nav.users', icon: Users, group: 'main', permission: 'tenant.users.view' },
  { to: '/brand', label: 'nav.brand', icon: Palette, group: 'manage', permission: 'tenant.settings.manage' },
  { to: '/billing', label: 'nav.billing', icon: CreditCard, group: 'manage', permission: 'tenant.billing.manage' },
  { to: '/support', label: 'nav.support', icon: LifeBuoy, group: 'manage', permission: 'tenant.support.approve' },
  { to: '/audit', label: 'nav.audit', icon: ScrollText, group: 'manage', permission: 'tenant.audit.view' },
];

const account: NavItem[] = [{ to: '/security', label: 'nav.security', icon: ShieldCheck, group: 'account' }];
// Profiles are kept per provider, so "My profile" appears inside a provider only.
const providerAccount: NavItem[] = [{ to: '/profile', label: 'nav.profile', icon: CircleUser, group: 'account' }];

/** Navigation for where the person is (platform console or a provider) and what they may do. */
export function navFor(me: Me): NavItem[] {
  const items = me.tenant ? provider : platform;
  return [
    ...items.filter((i) => !i.permission || me.permissions.includes(i.permission)),
    ...(me.tenant ? providerAccount : []),
    ...account,
  ];
}
