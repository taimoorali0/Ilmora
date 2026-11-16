import {
  barChartOutline, bookOutline, briefcaseOutline, calendarOutline, cardOutline, cashOutline, chatbubblesOutline, checkboxOutline,
  documentTextOutline, gridOutline, homeOutline, megaphoneOutline, peopleOutline, personCircleOutline, readerOutline, ribbonOutline, settingsOutline, walletOutline,
} from 'ionicons/icons';
import { hasRole } from './session';

/** Every module, who may open it, and where it sits in the menu. */
export interface NavItem { path: string; label: string; hint: string; icon: string; roles: string[]; group: string }

const STAFF_ROLES = ['owner', 'principal', 'admin', 'accountant', 'teacher', 'examiner'];

export const NAV: NavItem[] = [
  { path: '/home', label: 'Dashboard', hint: 'Today at a glance', icon: homeOutline, roles: STAFF_ROLES, group: '' },
  { path: '/families', label: 'Students & families', hint: 'Admissions, siblings, profiles', icon: peopleOutline, roles: ['owner', 'principal', 'admin', 'accountant'], group: 'Students' },
  { path: '/classes', label: 'Classes', hint: 'Sections, class teachers, strength', icon: gridOutline, roles: ['owner', 'principal', 'admin'], group: 'Students' },
  { path: '/attendance', label: 'Attendance', hint: 'Mark daily attendance', icon: checkboxOutline, roles: ['owner', 'principal', 'admin', 'teacher'], group: 'Students' },
  { path: '/attendance/report', label: 'Attendance report', hint: 'Monthly sheet, message parents', icon: calendarOutline, roles: ['owner', 'principal', 'admin', 'teacher'], group: 'Students' },
  { path: '/fees', label: 'Fees', hint: 'Vouchers, collection, defaulters', icon: cashOutline, roles: ['owner', 'principal', 'accountant'], group: 'Finance' },
  { path: '/expenses', label: 'Expenses', hint: 'School spending', icon: walletOutline, roles: ['owner', 'accountant'], group: 'Finance' },
  { path: '/payroll', label: 'Payroll', hint: 'Salaries and payslips', icon: cardOutline, roles: ['owner', 'principal', 'accountant'], group: 'Finance' },
  { path: '/exams', label: 'Exams & results', hint: 'Marks entry, result cards', icon: ribbonOutline, roles: ['owner', 'principal', 'examiner', 'teacher'], group: 'Academics' },
  { path: '/papers', label: 'Exam papers', hint: 'Teacher → examiner → principal', icon: documentTextOutline, roles: ['owner', 'principal', 'examiner', 'teacher'], group: 'Academics' },
  { path: '/syllabus', label: 'Syllabus', hint: 'Coverage by class and subject', icon: bookOutline, roles: ['owner', 'principal', 'teacher'], group: 'Academics' },
  { path: '/homework', label: 'Homework & worksheets', hint: 'Diary sent to parents', icon: readerOutline, roles: ['owner', 'principal', 'admin', 'teacher'], group: 'Academics' },
  { path: '/staff', label: 'Staff', hint: 'Attendance and leave', icon: briefcaseOutline, roles: ['owner', 'principal', 'admin'], group: 'School' },
  { path: '/messages', label: 'Parent messages', hint: 'WhatsApp and SMS', icon: chatbubblesOutline, roles: ['owner', 'principal', 'admin', 'accountant'], group: 'School' },
  { path: '/notices', label: 'Notices', hint: 'Announcements', icon: megaphoneOutline, roles: ['owner', 'principal', 'admin'], group: 'School' },
  { path: '/me', label: 'My attendance & pay', hint: 'Leave requests and payslips', icon: personCircleOutline, roles: ['principal', 'admin', 'accountant', 'teacher', 'examiner'], group: 'School' },
  { path: '/settings', label: 'Settings', hint: 'School, fees, users', icon: settingsOutline, roles: ['owner', 'principal'], group: 'School' },
];

export const myNav = (): NavItem[] => NAV.filter((n) => hasRole(...n.roles));
export const canOpen = (path: string): boolean => {
  const item = [...NAV].sort((a, b) => b.path.length - a.path.length).find((n) => path === n.path || path.startsWith(n.path + '/'));
  return !item || hasRole(...item.roles);
};
export const reportsIcon = barChartOutline;
