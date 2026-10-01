<script setup lang="ts">
/***************************************************************************
 * SubAttributeRow Component
 ***************************************************************************
 *
 * One sub-attribute, rendered as a REAL table row in the mapping table.
 *
 * It uses the same `.adt-fm-cell-*` classes as its parent row, so the browser
 * puts the name, the affix chip, the source picker and the remove control in
 * exactly the parent's columns. A grid inside a `colspan` cell cannot do that:
 * the table is `table-layout: fixed`, and its own column scaling does not
 * distribute as the declared 42/18/40 (measured 272/126/260 against a 720px
 * table), which left every nested column 10-14px right of the one it belongs
 * under.
 *
 * The source is `SourceControl`, the same one control the top-level row uses:
 * one searchable select holding the product fields plus the special sources,
 * which reveals a value input only when the chosen source needs one.
 *
 * Prefix and suffix are edited through `AffixControl` — the same pill and
 * popover the parent row uses, so the two surfaces finally treat the same two
 * fields the same way. It replaced a second table row holding two bare inputs.
 *
 * Nesting is shown by a tree connector in the checkbox gutter, and by nothing
 * else: no tint, no accent bar, no bracket rule. The row keeps the table's own
 * white background and row rule, so scanning a column down the table is not
 * broken by a block of foreign chrome (design canvas, Option A).
 *
 * Registry-seeded rows keep their tag as a fixed label; custom rows get a
 * free-text tag input validated by `validateSubAttributeTag`. Rows with
 * `source = 'zone'` are locked — the value resolves from WooCommerce shipping
 * zone data at generation time — but prefix/suffix stay editable (e.g. a ` USD`
 * suffix on g:price).
 *
 */
import { computed } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../../stores/fieldMappingStore';
import SourceControl from '../common/SourceControl.vue';
import AffixControl from '../common/AffixControl.vue';
import type { SubAttribute, SubAttributeSource } from '../../types';

interface Props {
  parentIndex: number;
  groupIndex: number;
  subIndex: number;
  sub: SubAttribute;
  registryTags: Set<string>;
  /** True for the first row of its group. */
  isFirst: boolean;
}

const props = defineProps<Props>();

const store = useFieldMappingStore();

const isRegistryTag = computed(() => props.sub.tag !== '' && props.registryTags.has(props.sub.tag));
const isZoneLocked = computed(() => props.sub.source === 'zone');

const errorKey = computed(() => `attribute-${props.parentIndex}-sub-${props.groupIndex}-${props.subIndex}`);
const errors = computed(() => store.getFieldErrors(errorKey.value));

const update = (updates: Partial<SubAttribute>) => {
  store.updateSubAttribute(props.parentIndex, props.groupIndex, props.subIndex, updates);
};

const onTagInput = (event: Event) => {
  update({ tag: (event.target as HTMLInputElement).value });
};

const onSourceChange = (payload: { source: string; source_value: string }) => {
  update({ source: payload.source as SubAttributeSource, source_value: payload.source_value });
};

const onRemove = () => {
  store.removeSubAttribute(props.parentIndex, props.groupIndex, props.subIndex);
};

const removeLabel = computed(() =>
  sprintf(
    /* translators: %s: sub-attribute tag name */
    __('Remove sub-attribute %s', 'woo-product-feed-pro'),
    props.sub.tag || __('(untitled)', 'woo-product-feed-pro')
  )
);
</script>

<template>
  <tr class="adt-sub-attribute-row">
    <!-- The gutter carries the whole nesting cue. -->
    <td class="adt-fm-cell-check adt-sub-attribute-gutter" aria-hidden="true">&#9500;</td>

    <td class="adt-fm-cell-attr">
      <div class="adt-sub-attribute-name">
        <span v-if="isRegistryTag" class="adt-sub-attribute-tag-label" :title="sub.tag">{{ sub.tag }}</span>
        <input
          v-else
          type="text"
          class="adt-fm-input"
          :class="{ 'adt-fm-input-error': errors.length > 0 }"
          :value="sub.tag"
          :placeholder="__('Sub-attribute name', 'woo-product-feed-pro')"
          :aria-label="__('Sub-attribute name', 'woo-product-feed-pro')"
          :aria-invalid="errors.length > 0"
          :aria-describedby="errors.length > 0 ? `${errorKey}-message` : undefined"
          @input="onTagInput"
        />
      </div>
      <p v-if="errors.length > 0" :id="`${errorKey}-message`" class="adt-field-error adt-sub-attribute-error">
        {{ errors[0] }}
      </p>
    </td>

    <td class="adt-fm-cell-transform">
      <AffixControl
        :prefix="sub.prefix"
        :suffix="sub.suffix"
        :row-label="sub.tag"
        @change="(payload) => update(payload)"
      />
    </td>

    <td class="adt-fm-cell-source">
      <span v-if="isZoneLocked" class="adt-sub-attribute-zone-lock">
        <span class="adt-tw-icon-[lucide--lock] adt-tw-size-4" aria-hidden="true"></span>
        {{ __('Auto (from zone)', 'woo-product-feed-pro') }}
      </span>
      <!--
        No `has-error`: this row's error key is written only by the TAG
        validator, so feeding it here reddened the source select for a bad tag.
      -->
      <SourceControl
        v-else
        :source="sub.source"
        :source-value="sub.source_value"
        allow-empty
        @change="onSourceChange"
      />
    </td>

    <td class="adt-fm-cell-actions">
      <button type="button" class="adt-fm-icon-btn" :aria-label="removeLabel" @click="onRemove">
        <span class="adt-tw-icon-[lucide--trash-2] adt-tw-size-4" aria-hidden="true"></span>
      </button>
    </td>
  </tr>

</template>
