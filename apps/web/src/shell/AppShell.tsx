import * as D from '@radix-ui/react-dialog';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { Link, Outlet, useRouterState } from '@tanstack/react-router';
import {
  ChevronDown,
  LogOut,
  Menu as MenuIcon,
  PanelLeftClose,
  PanelLeftOpen,
  Search,
  ShieldAlert,
  ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { Badge, cx } from '@/components/ui';
import { useT } from '@/lib/i18n';
import { initials, useMe, useSignOut } from '@/lib/session';
import { CommandPalette } from './CommandPalette';
import { navFor, type NavItem } from './nav';

/**
 * App shell from the mock-ups: light sidebar (full → icon rail → drawer on phones),
 * sticky top bar with ⌘K search, role chip and account menu.
 */
export function AppShell() {
  const me = useMe();
  const t = useT();
  const items = navFor(me);
  const [rail, setRail] = useState(() => localStorageGet('rail') === '1');
  const [drawer, setDrawer] = useState(false);
  const [palette, setPalette] = useState(false);
  const path = useRouterState({ select: (s) => s.location.pathname });
  const current = [...items].reverse().find((i) => (i.to === '/' ? path === '/' : path.startsWith(i.to)));

  const toggleRail = () => {
    setRail((r) => {
      localStorageSet('rail', r ? '0' : '1');
      return !r;
    });
  };

  return (
    <div
      className={cx(
        'min-h-dvh md:grid',
        rail ? 'md:grid-cols-[72px_1fr]' : 'md:grid-cols-[72px_1fr] xl:grid-cols-[248px_1fr]',
      )}
    >
      <aside className="sticky top-0 hidden h-dvh border-r border-line bg-surface md:block">
        <Sidebar items={items} rail={rail} />
      </aside>

      <D.Root open={drawer} onOpenChange={setDrawer}>
        <D.Portal>
          <D.Overlay className="fixed inset-0 z-40 bg-primary-950/30 md:hidden" />
          <D.Content
            className="fixed inset-y-0 left-0 z-50 w-[280px] animate-in bg-surface shadow-2xl md:hidden"
            onClick={() => setDrawer(false)}
          >
            <D.Title className="sr-only">Menu</D.Title>
            <Sidebar items={items} rail={false} forceFull />
          </D.Content>
        </D.Portal>
      </D.Root>

      <div className="min-w-0">
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-line bg-canvas/85 px-4 backdrop-blur-md sm:px-6">
          <button
            className="grid size-9 place-items-center rounded-md text-muted hover:bg-line-soft md:hidden"
            onClick={() => setDrawer(true)}
            aria-label="Open menu"
          >
            <MenuIcon className="size-5" />
          </button>
          <button
            className="hidden size-9 place-items-center rounded-md text-muted hover:bg-line-soft xl:grid"
            onClick={toggleRail}
            aria-label="Toggle sidebar"
          >
            {rail ? <PanelLeftOpen className="size-[18px]" /> : <PanelLeftClose className="size-[18px]" />}
          </button>
          <div className="min-w-0 truncate text-body">
            <span className="text-muted">{me.tenant?.name ?? t('shell.platform')}</span>
            {current ? (
              <>
                <span className="mx-2 text-faint">/</span>
                <span className="font-semibold">{t(current.label)}</span>
              </>
            ) : null}
          </div>

          <button
            onClick={() => setPalette(true)}
            className="ml-auto hidden h-9 w-[min(320px,28vw)] items-center gap-2 rounded-lg border border-line bg-surface px-3 text-small text-faint hover:border-primary-200 sm:flex"
          >
            <Search className="size-4" />
            {t('shell.command')}
            <kbd className="ml-auto rounded border border-line px-1.5 text-caption text-muted">⌘K</kbd>
          </button>
          <button
            onClick={() => setPalette(true)}
            className="ml-auto grid size-9 place-items-center rounded-md text-muted hover:bg-line-soft sm:hidden"
            aria-label={t('common.search')}
          >
            <Search className="size-[18px]" />
          </button>

          <AccountMenu />
        </header>

        {me.security.mfa_setup_required && path !== '/security' ? (
          <div className="mx-4 mt-4 flex items-center gap-3 rounded-lg bg-warning-bg px-4 py-3 text-small text-warning sm:mx-6">
            <ShieldAlert className="size-4 flex-none" />
            <span className="flex-1">{t('security.mfaRequired')}</span>
            <Link to="/security" className="font-semibold underline">
              {t('nav.security')}
            </Link>
          </div>
        ) : null}
        {me.support_access ? (
          <div className="mx-4 mt-4 flex items-center gap-3 rounded-lg bg-info-bg px-4 py-3 text-small text-info sm:mx-6">
            <ShieldCheck className="size-4" /> Support access to {me.tenant?.name} — read-only, ends{' '}
            {new Date(me.support_access.ends_at ?? '').toLocaleTimeString()}.
          </div>
        ) : null}

        <main className="mx-auto max-w-[1360px] px-4 py-6 sm:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>

      <CommandPalette items={items} open={palette} onOpenChange={setPalette} />
    </div>
  );
}

function Sidebar({ items, rail, forceFull }: { items: NavItem[]; rail: boolean; forceFull?: boolean }) {
  const me = useMe();
  const t = useT();
  const full = forceFull ? 'flex' : rail ? 'hidden' : 'hidden xl:flex';
  const label = forceFull ? '' : rail ? 'sr-only' : 'sr-only xl:not-sr-only';
  const groups: Array<{ key: NavItem['group']; title: string | null }> = [
    { key: 'main', title: null },
    { key: 'manage', title: 'Manage' },
    { key: 'account', title: 'Account' },
  ];

  return (
    <nav className="flex h-full flex-col px-3 py-4" aria-label="Main">
      <div className="flex items-center gap-3 px-2 pb-4">
        <span className="grid size-9 flex-none place-items-center rounded-xl bg-primary-700 text-[15px] font-bold text-white">
          {(me.tenant?.name ?? 'S').charAt(0)}
        </span>
        <div className={cx('min-w-0 flex-col', full)}>
          <span className="truncate text-[14px] font-semibold">{me.tenant?.name ?? t('app.name')}</span>
          <span className="truncate text-caption text-muted">
            {me.tenant ? t('shell.provider') : t('shell.platform')}
          </span>
        </div>
      </div>

      <div className="-mx-1 flex-1 overflow-y-auto px-1">
        {groups.map((g) => {
          const list = items.filter((i) => i.group === g.key);
          if (!list.length) return null;
          return (
            <div key={g.key} className="mt-3 first:mt-0">
              {g.title ? (
                <div
                  className={cx(
                    'px-3 pb-1.5 text-caption font-semibold uppercase tracking-wider text-faint',
                    full === 'flex' ? 'block' : rail ? 'hidden' : 'hidden xl:block',
                  )}
                >
                  {g.title}
                </div>
              ) : null}
              <ul className="grid gap-0.5">
                {list.map((item) => (
                  <li key={item.to}>
                    <Link
                      to={item.to}
                      activeOptions={{ exact: item.to === '/' }}
                      title={t(item.label)}
                      className="flex h-10 items-center gap-3 rounded-lg px-3 text-body text-muted transition-colors hover:bg-line-soft hover:text-ink data-[status=active]:bg-primary-50 data-[status=active]:font-medium data-[status=active]:text-primary-800"
                    >
                      <item.icon className="size-[18px] flex-none" />
                      <span className={cx('truncate', label)}>{t(item.label)}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          );
        })}
      </div>

      <div className="mt-3 flex items-center gap-3 border-t border-line px-2 pt-3">
        <span className="grid size-8 flex-none place-items-center rounded-full bg-primary-100 text-caption font-bold text-primary-800">
          {initials(me.user.first_name, me.user.last_name)}
        </span>
        <div className={cx('min-w-0 flex-col', full)}>
          <span className="truncate text-small font-medium">
            {me.user.first_name} {me.user.last_name}
          </span>
          <span className="truncate text-caption text-muted">{me.user.email ?? me.user.phone}</span>
        </div>
      </div>
    </nav>
  );
}

function AccountMenu() {
  const me = useMe();
  const t = useT();
  const signOut = useSignOut();
  const roles = [...me.platform_roles.map((r) => r.replace(/-/g, ' ')), ...me.roles.map((r) => r.label)];

  return (
    <Menu.Root>
      <Menu.Trigger className="flex h-9 items-center gap-2 rounded-lg bg-primary-950 px-3 text-small font-medium text-white hover:bg-primary-900">
        <span className="sm:hidden">{initials(me.user.first_name, me.user.last_name)}</span>
        <span className="hidden max-w-[180px] truncate capitalize sm:inline">{roles[0] ?? 'Member'}</span>
        <ChevronDown className="size-3.5 opacity-70" />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content align="end" sideOffset={8} className="card z-50 w-64 animate-in p-1.5 shadow-xl">
          <div className="px-2.5 py-2">
            <div className="text-small font-semibold">
              {me.user.first_name} {me.user.last_name}
            </div>
            <div className="mt-1.5 flex flex-wrap gap-1">
              {roles.map((r) => (
                <Badge key={r} tone="primary">
                  {r}
                </Badge>
              ))}
            </div>
          </div>
          <Menu.Separator className="my-1 h-px bg-line" />
          <Menu.Item asChild>
            <Link
              to="/security"
              className="flex items-center gap-2 rounded-md px-2.5 py-2 text-body outline-none data-[highlighted]:bg-line-soft"
            >
              <ShieldCheck className="size-4 text-muted" /> {t('nav.security')}
            </Link>
          </Menu.Item>
          <Menu.Item
            onSelect={() => void signOut()}
            className="flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-body text-danger outline-none data-[highlighted]:bg-danger-bg"
          >
            <LogOut className="size-4" /> {t('nav.signOut')}
          </Menu.Item>
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}

function localStorageGet(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function localStorageSet(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    /* ignore */
  }
}
