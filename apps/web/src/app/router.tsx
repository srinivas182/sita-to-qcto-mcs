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

// Applying to an intake: by link or QR, no account needed.
const join = createRoute({
  getParentRoute: () => root,
  path: '/join/$slug',
  component: lazyRouteComponent(() => import('@/features/intakes/JoinPage'), 'JoinPage'),
});

// Signing and checking agreements: by link, no account needed.
const sign = createRoute({
  getParentRoute: () => root,
  path: '/sign/$token',
  component: lazyRouteComponent(() => import('@/features/agreements/SignPage'), 'SignPage'),
});
const verify = createRoute({
  getParentRoute: () => root,
  path: '/verify/$code',
  component: lazyRouteComponent(() => import('@/features/agreements/VerifyPage'), 'VerifyPage'),
});

// The platform's own public site: pricing and applying to join.
const pricing = createRoute({
  getParentRoute: () => root,
  path: '/pricing',
  component: lazyRouteComponent(() => import('@/features/apply/PricingPage'), 'PricingPage'),
});
const apply = createRoute({
  getParentRoute: () => root,
  path: '/apply',
  component: lazyRouteComponent(() => import('@/features/apply/ApplyPage'), 'ApplyPage'),
});
const applyResume = createRoute({
  getParentRoute: () => root,
  path: '/apply/resume',
  validateSearch: (search: Record<string, unknown>): { ref?: string } => ({
    ref: typeof search.ref === 'string' ? search.ref : undefined,
  }),
  component: lazyRouteComponent(() => import('./ApplyResumeRoute'), 'ApplyResumeRoute'),
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
const applications = createRoute({
  getParentRoute: () => app,
  path: '/applications',
  component: lazyRouteComponent(() => import('@/features/admin/ApplicationsPage'), 'ApplicationsPage'),
});
const application = createRoute({
  getParentRoute: () => app,
  path: '/applications/$id',
  component: lazyRouteComponent(() => import('@/features/admin/ApplicationDetailPage'), 'ApplicationDetailPage'),
});
const catalogue = createRoute({
  getParentRoute: () => app,
  path: '/catalogue',
  component: lazyRouteComponent(() => import('@/features/catalogue/CatalogueAdminPage'), 'CatalogueAdminPage'),
});
const catalogueEntry = createRoute({
  getParentRoute: () => app,
  path: '/catalogue/$id',
  component: lazyRouteComponent(
    () => import('@/features/catalogue/QualificationEditorPage'),
    'QualificationEditorPage',
  ),
});
const programmes = createRoute({
  getParentRoute: () => app,
  path: '/programmes',
  component: lazyRouteComponent(() => import('@/features/programmes/ProgrammesPage'), 'ProgrammesPage'),
});
const employers = createRoute({
  getParentRoute: () => app,
  path: '/employers',
  component: lazyRouteComponent(() => import('@/features/employers/EmployersPage'), 'EmployersPage'),
});
const employer = createRoute({
  getParentRoute: () => app,
  path: '/employers/$id',
  component: lazyRouteComponent(() => import('@/features/employers/EmployerPage'), 'EmployerPage'),
});
const intakes = createRoute({
  getParentRoute: () => app,
  path: '/intakes',
  component: lazyRouteComponent(() => import('@/features/intakes/IntakesPage'), 'IntakesPage'),
});
const intake = createRoute({
  getParentRoute: () => app,
  path: '/intakes/$id',
  component: lazyRouteComponent(() => import('@/features/intakes/IntakePage'), 'IntakePage'),
});
const cohorts = createRoute({
  getParentRoute: () => app,
  path: '/cohorts',
  component: lazyRouteComponent(() => import('@/features/cohorts/CohortsPage'), 'CohortsPage'),
});
const cohort = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id',
  component: lazyRouteComponent(() => import('@/features/cohorts/CohortPage'), 'CohortPage'),
});
const agreements = createRoute({
  getParentRoute: () => app,
  path: '/agreements',
  component: lazyRouteComponent(() => import('@/features/agreements/AgreementsPage'), 'AgreementsPage'),
});
const agreement = createRoute({
  getParentRoute: () => app,
  path: '/agreements/$id',
  component: lazyRouteComponent(() => import('@/features/agreements/AgreementPage'), 'AgreementPage'),
});
const agreementTemplates = createRoute({
  getParentRoute: () => app,
  path: '/agreements/templates',
  component: lazyRouteComponent(() => import('@/features/agreements/TemplatesPage'), 'TemplatesPage'),
});
const agreementTemplate = createRoute({
  getParentRoute: () => app,
  path: '/agreements/templates/$id',
  component: lazyRouteComponent(() => import('@/features/agreements/TemplateEditorPage'), 'TemplateEditorPage'),
});
const roles = createRoute({
  getParentRoute: () => app,
  path: '/roles',
  component: lazyRouteComponent(() => import('@/features/people/RolesPage'), 'RolesPage'),
});
const credentials = createRoute({
  getParentRoute: () => app,
  path: '/people/credentials',
  component: lazyRouteComponent(() => import('@/features/people/CredentialsPage'), 'CredentialsPage'),
});
const profile = createRoute({
  getParentRoute: () => app,
  path: '/profile',
  component: lazyRouteComponent(() => import('@/features/people/ProfilePage'), 'ProfilePage'),
});
const centres = createRoute({
  getParentRoute: () => app,
  path: '/centres',
  component: lazyRouteComponent(() => import('@/features/centres/CentresPage'), 'CentresPage'),
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
const billing = createRoute({
  getParentRoute: () => app,
  path: '/billing',
  validateSearch: (search: Record<string, unknown>): { payment?: string; cancelled?: string } => ({
    payment: typeof search.payment === 'string' ? search.payment : undefined,
    cancelled: typeof search.cancelled === 'string' ? search.cancelled : undefined,
  }),
  component: lazyRouteComponent(() => import('./BillingRoute'), 'BillingRoute'),
});
const messages = createRoute({
  getParentRoute: () => app,
  path: '/messages',
  component: lazyRouteComponent(() => import('@/features/messages/MessagesPage'), 'MessagesPage'),
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
  join,
  sign,
  verify,
  pricing,
  apply,
  applyResume,
  app.addChildren([
    home,
    applications,
    application,
    catalogue,
    catalogueEntry,
    programmes,
    employers,
    employer,
    intakes,
    intake,
    cohorts,
    cohort,
    agreements,
    agreementTemplates,
    agreementTemplate,
    agreement,
    roles,
    credentials,
    profile,
    centres,
    tenants,
    tenant,
    cells,
    staff,
    support,
    audit,
    users,
    brand,
    settings,
    billing,
    messages,
    security,
  ]),
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
