<template>
  <div class="letterhead">
    <div class="logo">{{ initials(school?.name ?? 'S') }}</div>
    <div>
      <h2>{{ school?.name }}</h2>
      <p>{{ [school?.address, school?.phone].filter(Boolean).join(' · ') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { initials } from '@/lib/school';
import { schoolId } from '@/lib/session';
import { raw } from '@/lib/store';
import { session } from '@/lib/session';

/** School name and address on every printed document. */
const school = computed(() => raw('schools').find((s) => s.id === schoolId()) ?? session.data?.user.school);
</script>
