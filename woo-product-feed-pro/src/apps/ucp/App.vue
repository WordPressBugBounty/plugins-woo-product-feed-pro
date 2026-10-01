<template>
  <div class="adt-ucp">
    <nav class="adt-tabs">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="adt-tab"
        :class="{ 'adt-tab--active': activeTab === tab.id }"
        @click="activeTab = tab.id"
      >
        {{ tab.label }}
        <span v-if="tab.id === 'eligibility' && eligibleCount !== null" class="adt-tab__badge">
          {{ formatCount(eligibleCount) }}
        </span>
      </button>
      <a v-if="ordersUrl" class="adt-tab adt-tab--link" :href="ordersUrl">
        {{ __('View AI orders', 'woo-product-feed-pro') }}&nbsp;↗
      </a>
    </nav>

    <p v-if="error" class="adt-note adt-note--error">{{ error }}</p>

    <StatusTab v-if="activeTab === 'status'" :status="status" :busy="busy" @refresh="loadStatus" @notify="setError" />
    <EligibilityTab v-else @notify="setError" @stats="onStats" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { __ } from '@wordpress/i18n';
import { getConfig, getStatus } from './api';
import type { EligibilityStats, StatusData } from './types';
import StatusTab from './components/StatusTab.vue';
import EligibilityTab from './components/EligibilityTab.vue';

const tabs = [
  { id: 'status', label: __('Status & Setup', 'woo-product-feed-pro') },
  { id: 'eligibility', label: __('Product Eligibility', 'woo-product-feed-pro') },
] as const;

type TabId = (typeof tabs)[number]['id'];

const activeTab = ref<TabId>('status');
const status = ref<StatusData | null>(null);
const liveStats = ref<EligibilityStats | null>(null);
const busy = ref(false);
const error = ref('');

// Agentic orders are native WooCommerce orders — link to the Orders
// screen filtered to UCP-attributed orders instead of reproducing a list.
const ordersUrl = getConfig().orders_url;

const eligibleCount = computed<number | null>(() => {
  const stats = liveStats.value ?? status.value?.stats ?? null;
  return stats ? stats.eligible : null;
});

const formatCount = (value: number): string => value.toLocaleString();

const setError = (message: string) => {
  error.value = message;
};

const onStats = (stats: EligibilityStats) => {
  liveStats.value = stats;
};

const loadStatus = async () => {
  busy.value = true;
  error.value = '';
  try {
    status.value = await getStatus();
  } catch (e) {
    error.value = e instanceof Error ? e.message : __('Failed to load AI Discovery status.', 'woo-product-feed-pro');
  } finally {
    busy.value = false;
  }
};

onMounted(loadStatus);
</script>
