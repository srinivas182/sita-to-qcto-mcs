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

// An offer of a place: accept (with consent) or decline, by link.
const offer = createRoute({
  getParentRoute: () => root,
  path: '/offer/$token',
  component: lazyRouteComponent(() => import('@/features/intakes/OfferPage'), 'OfferPage'),
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

const verifySor = createRoute({
  getParentRoute: () => root,
  path: '/sor/$code',
  component: lazyRouteComponent(() => import('@/features/assessment/VerifySorPage'), 'VerifySorPage'),
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
const applicant = createRoute({
  getParentRoute: () => app,
  path: '/applicants/$id',
  component: lazyRouteComponent(() => import('@/features/intakes/ApplicantPage'), 'ApplicantPage'),
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
const cohortQcto = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/qcto',
  component: lazyRouteComponent(() => import('@/features/cohorts/QctoPage'), 'QctoPage'),
});
const cohortTimetable = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/timetable',
  component: lazyRouteComponent(() => import('@/features/delivery/TimetablePage'), 'TimetablePage'),
});
const cohortAttendance = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/attendance',
  component: lazyRouteComponent(() => import('@/features/delivery/AttendancePage'), 'AttendancePage'),
});
const cohortRegisters = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/registers',
  component: lazyRouteComponent(() => import('@/features/delivery/RegistersPage'), 'RegistersPage'),
});
const myRegister = createRoute({
  getParentRoute: () => app,
  path: '/my-registers/$id',
  component: lazyRouteComponent(() => import('@/features/delivery/MyRegisterPage'), 'MyRegisterPage'),
});
const programmeMaterials = createRoute({
  getParentRoute: () => app,
  path: '/programmes/$id/materials',
  component: lazyRouteComponent(() => import('@/features/delivery/MaterialsPage'), 'MaterialsPage'),
});
const myCourse = createRoute({
  getParentRoute: () => app,
  path: '/my-course',
  component: lazyRouteComponent(() => import('@/features/delivery/MyCoursePage'), 'MyCoursePage'),
});
const programmeTasks = createRoute({
  getParentRoute: () => app,
  path: '/programmes/$id/tasks',
  component: lazyRouteComponent(() => import('@/features/delivery/TasksPage'), 'TasksPage'),
});
const cohortTasks = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/tasks',
  component: lazyRouteComponent(() => import('@/features/delivery/TaskResultsPage'), 'TaskResultsPage'),
});
const programmeLogbook = createRoute({
  getParentRoute: () => app,
  path: '/programmes/$id/logbook',
  component: lazyRouteComponent(() => import('@/features/logbook/LogbookRulesPage'), 'LogbookRulesPage'),
});
const cohortLogbook = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/logbook',
  component: lazyRouteComponent(() => import('@/features/logbook/CohortLogbookPage'), 'CohortLogbookPage'),
});
const mentorLogbooks = createRoute({
  getParentRoute: () => app,
  path: '/logbooks',
  component: lazyRouteComponent(() => import('@/features/logbook/MentorWeeksPage'), 'MentorWeeksPage'),
});
const programmeAssessments = createRoute({
  getParentRoute: () => app,
  path: '/programmes/$id/assessments',
  component: lazyRouteComponent(() => import('@/features/assessment/AssessmentPlanPage'), 'AssessmentPlanPage'),
});
const assessing = createRoute({
  getParentRoute: () => app,
  path: '/assessing',
  component: lazyRouteComponent(() => import('@/features/assessment/AssessingPage'), 'AssessingPage'),
});
const cohortResults = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/results',
  component: lazyRouteComponent(() => import('@/features/assessment/CohortResultsPage'), 'CohortResultsPage'),
});
const learnerResults = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/results/$enrolment',
  component: lazyRouteComponent(() => import('@/features/assessment/LearnerResultsPage'), 'LearnerResultsPage'),
});
const moderation = createRoute({
  getParentRoute: () => app,
  path: '/moderation',
  component: lazyRouteComponent(() => import('@/features/assessment/ModerationPage'), 'ModerationPage'),
});
const cohortModeration = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/moderation',
  component: lazyRouteComponent(() => import('@/features/assessment/CohortModerationPage'), 'CohortModerationPage'),
});
const readiness = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/statements',
  component: lazyRouteComponent(() => import('@/features/assessment/ReadinessPage'), 'ReadinessPage'),
});
const cohortFunding = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/funding',
  component: lazyRouteComponent(() => import('@/features/funding/FundingPage'), 'FundingPage'),
});
const grantAgreement = createRoute({
  getParentRoute: () => app,
  path: '/grant-agreements/$id',
  component: lazyRouteComponent(() => import('@/features/funding/AgreementPage'), 'AgreementPage'),
});
const stipendRun = createRoute({
  getParentRoute: () => app,
  path: '/stipend-runs/$id',
  component: lazyRouteComponent(() => import('@/features/funding/StipendRunPage'), 'StipendRunPage'),
});
const cohortEisa = createRoute({
  getParentRoute: () => app,
  path: '/cohorts/$id/eisa',
  component: lazyRouteComponent(() => import('@/features/assessment/EisaPage'), 'EisaPage'),
});
const myResults = createRoute({
  getParentRoute: () => app,
  path: '/my-results',
  component: lazyRouteComponent(() => import('@/features/assessment/MyResultsPage'), 'MyResultsPage'),
});
const marking = createRoute({
  getParentRoute: () => app,
  path: '/marking',
  component: lazyRouteComponent(() => import('@/features/delivery/MarkingPage'), 'MarkingPage'),
});
const classes = createRoute({
  getParentRoute: () => app,
  path: '/classes',
  component: lazyRouteComponent(() => import('@/features/delivery/ClassesPage'), 'ClassesPage'),
});
const classSession = createRoute({
  getParentRoute: () => app,
  path: '/classes/$id',
  component: lazyRouteComponent(() => import('@/features/delivery/SessionPage'), 'SessionPage'),
});
// The check-in code opens this (signed in; a learner without the app checks in in the browser).
const checkIn = createRoute({
  getParentRoute: () => app,
  path: '/c/$token',
  component: lazyRouteComponent(() => import('@/features/delivery/CheckInPage'), 'CheckInPage'),
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
  offer,
  sign,
  verify,
  verifySor,
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
    applicant,
    cohorts,
    cohort,
    cohortQcto,
    cohortTimetable,
    cohortAttendance,
    cohortRegisters,
    myRegister,
    programmeMaterials,
    myCourse,
    programmeTasks,
    cohortTasks,
    programmeLogbook,
    cohortLogbook,
    mentorLogbooks,
    programmeAssessments,
    assessing,
    cohortResults,
    learnerResults,
    myResults,
    readiness,
    cohortEisa,
    cohortFunding,
    grantAgreement,
    stipendRun,
    moderation,
    cohortModeration,
    marking,
    classes,
    classSession,
    checkIn,
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
