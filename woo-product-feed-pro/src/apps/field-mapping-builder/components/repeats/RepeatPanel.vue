<script setup lang="ts">
/***************************************************************************
 * RepeatPanel Component
 ***************************************************************************
 *
 * The extra values of a repeatable attribute, rendered as real table rows
 * directly after the parent's own row — the same construction, and the same
 * `.adt-sub-attribute-*` chrome, as `SubAttributePanel`. Repeating a scalar and
 * repeating a nested group are one idea, so they look and behave alike: a run
 * of indented rows in the parent's own columns, with move and remove controls
 * and one quiet "add" action on the closing row.
 *
 * VALUE ONE IS THE PARENT ROW. Every attribute keeps its first value in its own
 * Source cell, repeatable or not, so the mapping table's Source column always
 * means the same thing and a single-valued feed needs no migration. This panel
 * therefore starts numbering at two, and shows nothing at all until the user
 * asks for a second value.
 *
 * The channel declares the limit (`repeatable` in the channel class), so the
 * "add" action disappears at the specification's own maximum — 10 additional
 * images, 7 destinations — rather than at a number chosen here.
 *
 */
import { computed } from 'vue';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../../stores/fieldMappingStore';
import SourceControl from '../common/SourceControl.vue';
import AffixControl from '../common/AffixControl.vue';
import type { SpecRow, SubAttribute, SubAttributeSource } from '../../types';

interface Props {
  row: SpecRow;
  parentIndex: number;
}

const props = defineProps<Props>();

const store = useFieldMappingStore();

/** Groups of one untagged row: value 2, value 3, … in output order. */
const repeats = computed(() => store.getRepeats(props.parentIndex));

const canAdd = computed(() => store.canAddRepeat(props.parentIndex, props.row.repeatMax));

/** Values configured in total, counting the parent row's own. */
const usedCount = computed(() => repeats.value.length + 1);

/**
 * The number the "N of M values used." plural is keyed on.
 *
 * The sentence counts the CHANNEL'S allowance, not the used values, so the
 * plural follows `repeatMax`. Keying it on `usedCount` reads "1 of 10 value
 * used." in the state every user meets first — a mapped row with no repeats yet.
 */
const pluralCount = computed(() => props.row.repeatMax);

const update = (repeatIndex: number, updates: Partial<SubAttribute>) => {
  store.updateRepeat(props.parentIndex, repeatIndex, updates);
};

const onSourceChange = (repeatIndex: number, payload: { source: string; source_value: string }) => {
  update(repeatIndex, { source: payload.source as SubAttributeSource, source_value: payload.source_value });
};

/** Value one is the parent row, so the panel's first repeat is value two. */
const valueLabel = (repeatIndex: number) =>
  sprintf(
    /* translators: %d: position of the value in the attribute's output order */
    __('Value %d', 'woo-product-feed-pro'),
    repeatIndex + 2
  );
</script>

<template>
  <tr v-for="(group, repeatIndex) in repeats" :key="`repeat-${repeatIndex}`" class="adt-sub-attribute-row">
    <!-- The gutter carries the whole nesting cue, as it does for sub-attributes. -->
    <td class="adt-fm-cell-check adt-sub-attribute-gutter" aria-hidden="true">&#9500;</td>

    <td class="adt-fm-cell-attr">
      <span class="adt-sub-attribute-tag-label">{{ valueLabel(repeatIndex) }}</span>
    </td>

    <td class="adt-fm-cell-transform">
      <AffixControl
        :prefix="group[0].prefix"
        :suffix="group[0].suffix"
        :row-label="valueLabel(repeatIndex)"
        @change="(payload) => update(repeatIndex, payload)"
      />
    </td>

    <td class="adt-fm-cell-source">
      <SourceControl
        :source="group[0].source"
        :source-value="group[0].source_value"
        :choices="row.choices"
        @change="(payload) => onSourceChange(repeatIndex, payload)"
      />
    </td>

    <td class="adt-fm-cell-actions">
      <span class="adt-sub-attribute-group-tools">
        <button
          type="button"
          class="adt-fm-icon-btn"
          :disabled="repeatIndex === 0"
          :aria-label="sprintf(__('Move %s up', 'woo-product-feed-pro'), valueLabel(repeatIndex))"
          @click="store.moveRepeat(parentIndex, repeatIndex, -1)"
        >
          <span class="adt-tw-icon-[lucide--chevron-up] adt-tw-size-4" aria-hidden="true"></span>
        </button>
        <button
          type="button"
          class="adt-fm-icon-btn"
          :disabled="repeatIndex === repeats.length - 1"
          :aria-label="sprintf(__('Move %s down', 'woo-product-feed-pro'), valueLabel(repeatIndex))"
          @click="store.moveRepeat(parentIndex, repeatIndex, 1)"
        >
          <span class="adt-tw-icon-[lucide--chevron-down] adt-tw-size-4" aria-hidden="true"></span>
        </button>
        <button
          type="button"
          class="adt-fm-icon-btn"
          :aria-label="sprintf(__('Remove %s', 'woo-product-feed-pro'), valueLabel(repeatIndex))"
          @click="store.removeRepeat(parentIndex, repeatIndex)"
        >
          <span class="adt-tw-icon-[lucide--trash-2] adt-tw-size-4" aria-hidden="true"></span>
        </button>
      </span>
    </td>
  </tr>

  <tr class="adt-sub-attribute-row adt-sub-attribute-row--add">
    <td class="adt-fm-cell-check adt-sub-attribute-gutter" aria-hidden="true">&#9492;</td>
    <td colspan="4">
      <button v-if="canAdd" type="button" class="adt-sub-attribute-action" @click="store.addRepeat(parentIndex, row.repeatMax)">
        <span class="adt-tw-icon-[lucide--plus] adt-tw-size-3" aria-hidden="true"></span>
        {{ __('Add value', 'woo-product-feed-pro') }}
      </button>

      <span class="adt-sub-attribute-empty" :class="{ 'adt-tw-ml-2': canAdd }" aria-live="polite">
        {{
          sprintf(
            /* translators: 1: values configured, 2: maximum the channel allows */
            _n('%1$d of %2$d value used.', '%1$d of %2$d values used.', pluralCount, 'woo-product-feed-pro'),
            usedCount,
            row.repeatMax
          )
        }}
      </span>
    </td>
  </tr>
</template>
