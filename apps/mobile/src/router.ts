import { createRouter } from '@ionic/vue-router';
import { createMemoryHistory, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { canOpen, NAV } from './lib/nav';
import { IS_DEMO, hasRole, session } from './lib/session';

const NAV_PATHS = new Set(NAV.map((n) => n.path));
const page = (name: string) => () => import(`./views/${name}.vue`);

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/home' },
  { path: '/login', component: page('LoginPage'), meta: { public: true } },
  { path: '/home', component: page('HomePage') },
  { path: '/families', component: page('FamiliesPage') },
  { path: '/families/:id', component: page('FamilyPage'), props: true },
  { path: '/students/:id', component: page('StudentPage'), props: true },
  { path: '/classes', component: page('ClassesPage') },
  { path: '/attendance', component: page('AttendancePage') },
  { path: '/attendance/report', component: page('AttendanceReportPage') },
  { path: '/fees', component: page('FeesPage') },
  { path: '/voucher/:id', component: page('VoucherPage'), props: true },
  { path: '/receipt/:id', component: page('ReceiptPage'), props: true },
  { path: '/exams', component: page('ExamsPage') },
  { path: '/exams/:id', component: page('ExamPage'), props: true },
  { path: '/result/:examId/:studentId', component: page('ResultCardPage'), props: true },
  { path: '/papers', component: page('PapersPage') },
  { path: '/papers/:id', component: page('PaperPage'), props: true },
  { path: '/syllabus', component: page('SyllabusPage') },
  { path: '/homework', component: page('HomeworkPage') },
  { path: '/notices', component: page('NoticesPage') },
  { path: '/staff', component: page('StaffPage') },
  { path: '/payroll', component: page('PayrollPage') },
  { path: '/payslip/:id', component: page('PayslipPage'), props: true },
  { path: '/messages', component: page('MessagesPage') },
  { path: '/expenses', component: page('ExpensesPage') },
  { path: '/settings', component: page('SettingsPage') },
  { path: '/me', component: page('MyPage') },
  { path: '/parent', component: page('ParentPage') },
  { path: '/platform', component: page('PlatformPage') },
];

// The hosted demo runs inside a frame that does not keep URL paths, so it routes in memory.
export const router = createRouter({ history: IS_DEMO ? createMemoryHistory() : createWebHistory(import.meta.env.BASE_URL), routes });

/** Where a signed-in user lands. */
export function homeFor(): string {
  if (session.data?.user.super) return '/platform';
  return hasRole('parent') && !hasRole('owner', 'principal', 'admin', 'accountant', 'teacher', 'examiner') ? '/parent' : '/home';
}

router.beforeEach((to) => {
  if (!to.meta.public && !session.data) return '/login';
  if (!session.data) return;
  const home = homeFor();
  if (to.path === '/login') return home;
  if (home !== '/home' && to.path === '/home') return home;
  if (session.data.user.super && to.path !== '/platform') return '/platform';
  if (!session.data.user.super && to.path === '/platform') return home;
  if (home === '/parent' && to.path !== '/parent' && !/^\/(voucher|receipt|result)\//.test(to.path)) return '/parent';
  if (home === '/home' && (to.path === '/parent' || !canOpen(to.path))) return '/home';
});

/**
 * Where the back arrow goes. Kept here because the demo routes in memory, where
 * the browser's own history is not available.
 */
export const trail: string[] = [];
router.afterEach((to) => {
  if (trail[trail.length - 2] === to.fullPath) trail.pop();
  else if (NAV_PATHS.has(to.path) || to.path === '/login' || to.path === '/parent' || to.path === '/platform') trail.splice(0, trail.length, to.fullPath);
  else if (trail[trail.length - 1] !== to.fullPath) trail.push(to.fullPath);
});
export function goBack(fallback: string): void {
  router.replace(trail.length > 1 ? trail[trail.length - 2] : fallback);
}
