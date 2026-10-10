import { createRouter, createWebHistory } from '@ionic/vue-router';
import type { RouteRecordRaw } from 'vue-router';
import { session } from './lib/session';

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/home' },
  { path: '/login', component: () => import('./views/LoginPage.vue'), meta: { public: true } },
  { path: '/home', component: () => import('./views/HomePage.vue') },
  { path: '/families', component: () => import('./views/FamiliesPage.vue') },
  { path: '/families/:id', component: () => import('./views/FamilyPage.vue'), props: true },
  { path: '/families/:familyId/students/:id', component: () => import('./views/StudentPage.vue'), props: true },
  { path: '/attendance', component: () => import('./views/AttendancePage.vue') },
  { path: '/sync-lab', component: () => import('./views/SyncLabPage.vue') },
];

export const router = createRouter({ history: createWebHistory(import.meta.env.BASE_URL), routes });

router.beforeEach((to) => {
  if (!to.meta.public && !session.data) return '/login';
  if (to.path === '/login' && session.data) return '/home';
});
