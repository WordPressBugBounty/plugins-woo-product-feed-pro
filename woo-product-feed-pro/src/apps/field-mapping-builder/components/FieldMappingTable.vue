<script setup lang="ts">
/***************************************************************************
 * FieldMappingTable Component
 ***************************************************************************
 *
 * Centre column: the mapping table. Rows come from `specRows`, which is the
 * channel's whole catalog joined with the saved mapping — so unmapped
 * attributes are visible and inert rather than absent. Rows are grouped under
 * the catalog's own group labels when no single group is selected.
 *
 */
import { computed, nextTick, ref, watch } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../stores/fieldMappingStore';
import FieldMappingRow from './rows/FieldMappingRow.vue';

const store = useFieldMappingStore();

const emit = defineEmits<{ (e: 'add-custom-field'): void }>();

const hasChannel = computed(() => Object.keys(store.channelAttributes).length > 0);

/**
 * Reveal a row the store has just created.
 *
 * A new custom field lands at the bottom of the last group, well below the fold,
 * so without this the user cannot tell the button did anything. Done here rather
 * than in the row: the table renders the list, so it can wait for the row to
 * exist and then find it by key.
 */
watch(
  () => store.focusRowKey,
  async (key) => {
    if (!key) return;

    await nextTick();

    const row = document.querySelector(`[data-row-key="${key}"]`);
    store.focusRowKey = null;
    if (!row) return;

    row.scrollIntoView({ block: 'center' });
    (row.querySelector('.adt-fm-input-name') as HTMLInputElement | null)?.focus();
  }
);

// Bulk-delete selection, keyed by the row's stable key (the legacy `record`
// checkboxes). Only mapped rows can be selected — an inert row has nothing to
// delete.
const selectedKeys = ref<Set<string>>(new Set());

/*
 * Prune, do not reset: a key can stop existing while the user still has other
 * rows ticked, and clearing everything would lose their selection.
 *
 * A non-catalog row's key is `extra:<index>` — its position in feedAttributes —
 * so removing a row above a ticked one shifts the indices and the tick silently
 * re-points at a different attribute. Dropping keys that no longer exist keeps
 * the bulk bar's count honest and stops Delete acting on a row nobody picked.
 */
watch(
  () => store.specRows,
  (rows) => {
    const live = new Set(rows.map((row) => row.key));
    if ([...selectedKeys.value].every((key) => live.has(key))) {
      return;
    }
    selectedKeys.value = new Set([...selectedKeys.value].filter((key) => live.has(key)));
  }
);

const setRowSelected = (key: string, selected: boolean) => {
  const next = new Set(selectedKeys.value);
  if (selected) {
    next.add(key);
  } else {
    next.delete(key);
  }
  selectedKeys.value = next;
};

const selectableKeys = computed(() => store.renderedRows.filter((r) => r.attrIndex !== null).map((r) => r.key));

const allSelected = computed(
  () => selectableKeys.value.length > 0 && selectableKeys.value.every((k) => selectedKeys.value.has(k))
);

const toggleAll = (event: Event) => {
  selectedKeys.value = (event.target as HTMLInputElement).checked ? new Set(selectableKeys.value) : new Set();
};

const deleteSelected = () => {
  if (selectedKeys.value.size === 0) return;

  // eslint-disable-next-line no-alert -- legacy parity: the old tab confirms bulk deletes.
  if (!window.confirm(__('Are you sure you want to delete the selected field mappings?', 'woo-product-feed-pro'))) {
    return;
  }

  // Resolve to attribute indices first, then remove bottom-up so the remaining
  // indices stay valid while splicing.
  const indices = store.specRows
    .filter((row) => selectedKeys.value.has(row.key) && row.attrIndex !== null)
    .map((row) => row.attrIndex as number)
    .sort((a, b) => b - a);

  indices.forEach((index) => store.removeAttribute(index));
  selectedKeys.value = new Set();
};
</script>

<template>
  <div class="adt-fm-table-wrap">
    <div v-if="selectedKeys.size > 0" class="adt-fm-bulk-bar">
      <span>
        <b>{{ selectedKeys.size }}</b> {{ __('selected', 'woo-product-feed-pro') }}
      </span>
      <div class="adt-fm-bulk-actions">
        <button type="button" class="adt-button adt-button-sm" @click="deleteSelected">
          <span class="adt-tw-icon-[lucide--trash-2] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
          {{ __('Delete', 'woo-product-feed-pro') }}
        </button>
      </div>
      <button
        type="button"
        class="adt-fm-icon-btn"
        :aria-label="__('Clear selection', 'woo-product-feed-pro')"
        @click="selectedKeys = new Set()"
      >
        <span class="adt-tw-icon-[lucide--x] adt-tw-size-4" aria-hidden="true"></span>
      </button>
    </div>

    <table class="adt-fm-table">
      <thead>
        <tr>
          <th class="adt-fm-cell-check">
            <input
              type="checkbox"
              :checked="allSelected"
              :aria-label="__('Select all mapped rows', 'woo-product-feed-pro')"
              @change="toggleAll"
            />
          </th>
          <th class="adt-fm-cell-attr">{{ __('Feed attribute', 'woo-product-feed-pro') }}</th>
          <!--
            "Affix", not "Transform" or "Prefix / suffix": one short word fits
            the narrowed column in any language. "Transform" was a single long
            word that overflowed its track and painted over the next header; the
            design's regex transform mode was deliberately never built, and find
            & replace lives in Rules.
          -->
          <th class="adt-fm-cell-transform">{{ __('Affix', 'woo-product-feed-pro') }}</th>
          <th class="adt-fm-cell-source">{{ __('Source from WooCommerce', 'woo-product-feed-pro') }}</th>
          <th class="adt-fm-cell-actions"></th>
        </tr>
      </thead>

      <template v-if="hasChannel">
        <tbody v-for="group in store.groupedVisibleRows" :key="group.label" class="adt-fm-group">
          <tr v-if="store.activeGroup === 'all'" class="adt-fm-group-head">
            <td colspan="5">
              <span class="adt-tw-icon-[lucide--folder] adt-tw-size-4" aria-hidden="true"></span>
              <span class="adt-fm-group-label">{{ group.label }}</span>
              <span class="adt-fm-group-count">
                {{ store.groupSummary[group.label]?.mapped ?? 0 }}/{{ store.groupSummary[group.label]?.total ?? 0 }}
              </span>
            </td>
          </tr>

          <FieldMappingRow
            v-for="row in group.rows"
            :key="row.key"
            :row="row"
            :selected="selectedKeys.has(row.key)"
            @update:selected="(value: boolean) => setRowSelected(row.key, value)"
          />

          <!--
            The optional tail of the group. Kept out of the table by default so
            the page stays short, but announced so the catalog is still findable.
          -->
          <tr v-if="group.hiddenCount > 0" class="adt-fm-more-row">
            <td></td>
            <td colspan="4">
              <button type="button" class="adt-fm-more-button" @click="store.toggleGroupExpanded(group.label)">
                <span class="adt-tw-icon-[lucide--chevron-down] adt-tw-size-4" aria-hidden="true"></span>
                {{
                  sprintf(
                    /* translators: %d: number of optional attributes not shown */
                    __('Show %d more attributes', 'woo-product-feed-pro'),
                    group.hiddenCount
                  )
                }}
              </button>
            </td>
          </tr>

          <tr v-else-if="store.isBrowsingAll && store.expandedGroups.has(group.label)" class="adt-fm-more-row">
            <td></td>
            <td colspan="4">
              <button type="button" class="adt-fm-more-button" @click="store.toggleGroupExpanded(group.label)">
                <span class="adt-tw-icon-[lucide--chevron-up] adt-tw-size-4" aria-hidden="true"></span>
                {{ __('Show only the fields in use', 'woo-product-feed-pro') }}
              </button>
            </td>
          </tr>
        </tbody>
      </template>

      <tbody v-else>
        <tr>
          <td colspan="5" class="adt-tw-text-center">
            {{ __("You haven't selected a channel for this feed yet.", 'woo-product-feed-pro') }}
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="hasChannel && !store.renderedRows.length && !store.visibleRows.length" class="adt-fm-empty">
      {{ __('No fields match the current filters.', 'woo-product-feed-pro') }}
    </p>

    <div class="adt-fm-add-zone">
      <!--
        Legacy parity. The old tab listed only mapped rows, so this button
        appended one with a channel-attribute dropdown. Every attribute is
        already a row here, so it opens the catalog instead.
      -->
      <button
        type="button"
        class="adt-button adt-button-sm"
        :title="__('Show every attribute this channel offers, with the filters cleared.', 'woo-product-feed-pro')"
        @click="store.browseAllAttributes()"
      >
        <span class="adt-tw-icon-[lucide--plus] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
        {{ __('Add field mapping', 'woo-product-feed-pro') }}
      </button>

      <button type="button" class="adt-button adt-button-sm" @click="emit('add-custom-field')">
        <span class="adt-tw-icon-[lucide--plus] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
        {{ __('Add custom field', 'woo-product-feed-pro') }}
      </button>
      <span class="adt-fm-add-hint">
        {{ sprintf(__('%d attributes available for this channel.', 'woo-product-feed-pro'), store.specRows.length) }}
      </span>
    </div>
  </div>
</template>
