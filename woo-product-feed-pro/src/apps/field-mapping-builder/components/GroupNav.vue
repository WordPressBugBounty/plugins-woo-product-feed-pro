<script setup lang="ts">
/***************************************************************************
 * GroupNav Component
 ***************************************************************************
 *
 * Left column of the builder: field search, a required-fields progress card,
 * and the channel's own catalog groups with per-group mapped counts. Groups are
 * the labels the channel class ships (Google has 9), never an invented set.
 *
 */
import { computed } from 'vue';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../stores/fieldMappingStore';

const store = useFieldMappingStore();

const summary = computed(() => store.overallSummary);

const requiredProgress = computed(() => {
  const { required, missing } = summary.value;
  if (required <= 0) return 100;
  return Math.round(((required - missing) / required) * 100);
});

// Preserve catalog order; `groupSummary` is keyed by the catalog's group labels.
const groups = computed(() => Object.keys(store.groupSummary));

const groupCountLabel = (group: string) => {
  const bucket = store.groupSummary[group];
  return bucket ? `${bucket.mapped}/${bucket.total}` : '';
};

const missingTitle = (count: number) =>
  sprintf(
    /* translators: %d: number of required fields with no mapping */
    _n('%d required field still missing', '%d required fields still missing', count, 'woo-product-feed-pro'),
    count
  );
</script>

<template>
  <aside class="adt-fm-nav">
    <div class="adt-fm-nav-search">
      <span class="adt-tw-icon-[lucide--search] adt-tw-size-4 adt-tw-text-gray-400" aria-hidden="true"></span>
      <input
        v-model="store.search"
        type="search"
        class="adt-fm-nav-search-input"
        :placeholder="__('Search fields…', 'woo-product-feed-pro')"
        :aria-label="__('Search fields', 'woo-product-feed-pro')"
      />
    </div>

    <div class="adt-fm-progress">
      <div class="adt-fm-progress-row">
        <span class="adt-fm-progress-label">{{ __('Required fields', 'woo-product-feed-pro') }}</span>
        <span class="adt-fm-progress-value">{{ summary.required - summary.missing }}/{{ summary.required }}</span>
      </div>
      <div
        class="adt-fm-progress-bar"
        role="progressbar"
        :aria-valuenow="requiredProgress"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="__('Required fields mapped', 'woo-product-feed-pro')"
      >
        <div class="adt-fm-progress-fill" :style="{ width: `${requiredProgress}%` }"></div>
      </div>
      <div class="adt-fm-progress-stats">
        <span><b>{{ summary.mapped }}</b> {{ __('mapped', 'woo-product-feed-pro') }}</span>
        <span :class="{ 'adt-fm-stat-warn': summary.missing > 0 }">
          <b>{{ summary.missing }}</b> {{ __('missing', 'woo-product-feed-pro') }}
        </span>
        <span><b>{{ summary.total - summary.mapped }}</b> {{ __('unmapped', 'woo-product-feed-pro') }}</span>
      </div>
    </div>

    <div class="adt-fm-nav-title">{{ __('Groups', 'woo-product-feed-pro') }}</div>
    <nav class="adt-fm-nav-groups" :aria-label="__('Attribute groups', 'woo-product-feed-pro')">
      <button
        type="button"
        class="adt-fm-nav-group"
        :class="{ active: store.activeGroup === 'all' }"
        :aria-current="store.activeGroup === 'all'"
        @click="store.activeGroup = 'all'"
      >
        <span class="adt-tw-icon-[lucide--layout-grid] adt-tw-size-4" aria-hidden="true"></span>
        <span class="adt-fm-nav-group-label">{{ __('All fields', 'woo-product-feed-pro') }}</span>
        <span class="adt-fm-nav-count">{{ summary.mapped }}/{{ summary.total }}</span>
      </button>

      <button
        v-for="group in groups"
        :key="group"
        type="button"
        class="adt-fm-nav-group"
        :class="{ active: store.activeGroup === group }"
        :aria-current="store.activeGroup === group"
        @click="store.activeGroup = group"
      >
        <span class="adt-tw-icon-[lucide--folder] adt-tw-size-4" aria-hidden="true"></span>
        <span class="adt-fm-nav-group-label" :title="group">{{ group }}</span>
        <span
          v-if="store.groupSummary[group]?.missing"
          class="adt-fm-dot adt-fm-dot-warn"
          :title="missingTitle(store.groupSummary[group].missing)"
        ></span>
        <span class="adt-fm-nav-count">{{ groupCountLabel(group) }}</span>
      </button>
    </nav>

    <div class="adt-fm-nav-title">{{ __('Legend', 'woo-product-feed-pro') }}</div>
    <ul class="adt-fm-legend">
      <li><span class="adt-fm-dot adt-fm-dot-ok"></span> {{ __('Mapped', 'woo-product-feed-pro') }}</li>
      <li><span class="adt-fm-dot adt-fm-dot-warn"></span> {{ __('Required, not mapped', 'woo-product-feed-pro') }}</li>
      <li><span class="adt-fm-dot adt-fm-dot-gray"></span> {{ __('Not mapped (optional)', 'woo-product-feed-pro') }}</li>
      <li><span class="adt-fm-dot adt-fm-dot-violet"></span> {{ __('Static value', 'woo-product-feed-pro') }}</li>
    </ul>
  </aside>
</template>
