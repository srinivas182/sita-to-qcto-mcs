import type { QueryClient } from '@tanstack/react-query';
import {
  createRootRouteWithContext,
  createRoute,
  createRouter,
  lazyRouteComponent,
  Outlet,
  redirect,
} from '@tanstack/react-router';
import { meQuery } from '@/lib/session';
import { SignInPage } from '@/features/auth/SignInPage';
import { NotFound, RouteError } from './RouteStates';

interface Context {
  queryClient: QueryClient;
}

const root = createRootRouteWithContext<Context>()({
  component: Outlet,
  notFoundComponent: NotFound,
  errorComponent: RouteError,
});

// Public screens. Sign-in is in the main bundle: it is the first paint for most visits.
const signIn = createRoute({
  getParentRoute: () => root,
  path: '/sign-in',
  component: SignInPage,
  beforeLoad: async ({ context }) => {
    const me = await context.queryClient.ensureQueryData(meQuery);
    if (me) throw redirect({ to: '/' });
  },
});
const forgot = createRoute({
  getParentRoute: () => root,
  path: '/forgot-password',
  component: lazyRouteComponent(() => import('@/features/auth/PasswordPages'), 'ForgotPasswordPage'),
});
const reset = createRoute({
  getParentRoute: () => root,
  path: '/reset-password',
  component: lazyRouteComponent(() => import('@/features/auth/PasswordPages'), 'ResetPasswordPage'),
});
const invite = createRoute({
  getParentRoute: () => root,
  path: '/invite/$token',
  component: lazyRouteComponent(() => import('@/features/auth/AcceptInvitationPage'), 'AcceptInvitationPage'),
});

// Signed-in area. Everything below is code-split per screen.
const app = createRoute({
  getParentRoute: () => root,
  id: 'app',
  // The shell is loaded after sign-in, keeping the sign-in page's first load small.
  component: lazyRouteComponent(() => import('@/shell/AppShell'), 'AppShell'),
  beforeLoad: async ({ context, location }) => {
    const me = await context.queryClient.ensureQueryData(meQuery);
    if (!me) throw redirect({ to: '/sign-in', search: { next: location.href } as never });
    if (me.security.mfa_setup_required && location.pathname !== '/security') throw redirect({ to: '/security' });
    return { me };
  },
});

const home = createRoute({
  getParentRoute: () => app,
  path: '/',
  component: lazyRouteComponent(() => import('./Home'), 'Home'),
});
const tenants = createRoute({
  getParentRoute: () => app,
  path: '/tenants',
  component: lazyRouteComponent(() => import('@/features/admin/TenantsPage'), 'TenantsPage'),
});
const tenant = createRoute({
  getParentRoute: () => app,
  path: '/tenants/$id',
  component: lazyRouteComponent(() => import('@/features/admin/TenantDetailPage'), 'TenantDetailPage'),
});
const cells = createRoute({
  getParentRoute: () => app,
  path: '/cells',
  component: lazyRouteComponent(() => import('@/features/admin/CellsPage'), 'CellsPage'),
});
const staff = createRoute({
  getParentRoute: () => app,
  path: '/staff',
  component: lazyRouteComponent(() => import('@/features/admin/StaffPage'), 'StaffPage'),
});
const support = createRoute({
  getParentRoute: () => app,
  path: '/support',
  component: lazyRouteComponent(() => import('@/features/support/SupportPage'), 'SupportPage'),
});
const audit = createRoute({
  getParentRoute: () => app,
  path: '/audit',
  component: lazyRouteComponent(() => import('@/features/audit/AuditPage'), 'AuditPage'),
});
const users = createRoute({
  getParentRoute: () => app,
  path: '/users',
  component: lazyRouteComponent(() => import('@/features/provider/UsersPage'), 'UsersPage'),
});
const brand = createRoute({
  getParentRoute: () => app,
  path: '/brand',
  component: lazyRouteComponent(() => import('@/features/provider/BrandPage'), 'BrandPage'),
});
const settings = createRoute({
  getParentRoute: () => app,
  path: '/settings',
  validateSearch: (search: Record<string, unknown>) => ({
    tab: typeof search.tab === 'string' ? search.tab : undefined,
  }),
  component: lazyRouteComponent(() => import('@/features/settings/SettingsPage'), 'SettingsPage'),
});
const security = createRoute({
  getParentRoute: () => app,
  path: '/security',
  component: lazyRouteComponent(() => import('@/features/security/SecurityPage'), 'SecurityPage'),
});

const routeTree = root.addChildren([
  signIn,
  forgot,
  reset,
  invite,
  app.addChildren([home, tenants, tenant, cells, staff, support, audit, users, brand, settings, security]),
]);

export function makeRouter(queryClient: QueryClient) {
  return createRouter({
    routeTree,
    context: { queryClient },
    defaultPreload: 'intent', // prefetch code and data on hover/focus: navigation feels instant
    defaultPreloadStaleTime: 0,
    scrollRestoration: true,
  });
}

declare module '@tanstack/react-router' {
  interface Register {
    router: ReturnType<typeof makeRouter>;
  }
}
