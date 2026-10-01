<script setup lang="ts">
/***************************************************************************
 * SubAttributePanel Component
 ***************************************************************************
 *
 * The parent attribute's sub-attributes, rendered as a run of REAL table rows
 * directly after the parent's own row, so every nested control sits in the
 * parent's columns. See `SubAttributeRow.vue` for why a grid could not.
 *
 * Emits, per group: a header row ONLY when a parent has more than one group
 * (group order is output order, so it needs naming and move controls then — and
 * only then), the group's sub-attribute rows, and a closing row carrying
 * "Add sub-attribute". The last group's closing row also carries "Add group",
 * so the parent-level control appears once.
 *
 * The chrome is deliberately minimal (design canvas, Option A): a single group
 * shows no header and no group label at all — the tree connectors in the gutter
 * and the parent row above them are the only nesting cues needed.
 *
 * `zones_auto` parents hold a single template group, expanded per WooCommerce
 * shipping zone at generation time, so they show neither group chrome nor
 * "Add group".
 *
 * Group order is changed with explicit move controls rather than by dragging: a
 * group is now several sibling `<tr>`s rather than one box, so there is no single
 * node to drag. The rows live in the mapping table's own `<tbody>`, which already
 * holds one tbody per catalog group, so they cannot be wrapped in one either.
 *
 */
import { computed } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../../stores/fieldMappingStore';
import SubAttributeRow from '../rows/SubAttributeRow.vue';
import type { FeedAttribute, SubAttributeGroup } from '../../types';

interface Props {
  parentIndex: number;
  attribute: FeedAttribute;
}

const props = defineProps<Props>();

const store = useFieldMappingStore();

const zonesAuto = computed(() => !!props.attribute.zones_auto);

const registryTags = computed(() => store.getRegistryTags(props.attribute.feed_name));

const groups = computed<SubAttributeGroup[]>(() => props.attribute.sub_attributes ?? []);

/** Group chrome only earns its row when the order is actually meaningful. */
const showGroupChrome = computed(() => !zonesAuto.value && groups.value.length > 1);

/**
 * A parent with sub-attributes does not write its own value.
 *
 * `Sub_Attribute_Resolver::apply_to_payload()` REPLACES the parent's payload
 * with the resolved groups, so a row that also has a source mapped loses that
 * value with nothing in the output to show it: nesting on `g:title` silently
 * drops the title text. Since any attribute can be nested, that is easy to do by
 * accident, so say it where the groups are edited.
 *
 * Zone parents are exempt — for `g:shipping` the groups have always been the
 * value, on both the legacy and the new path.
 */
const showValueOverrideNotice = computed(
  () => !zonesAuto.value && groups.value.length > 0 && '' !== (props.attribute.source_value ?? '')
);

const groupLabel = (groupIndex: number) =>
  sprintf(
    /* translators: %d: group number */
    __('Group %d', 'woo-product-feed-pro'),
    groupIndex + 1
  );

/** Swap a group with its neighbour; group order is output order. */
const moveGroup = (groupIndex: number, offset: number) => {
  const next = groupIndex + offset;
  if (next < 0 || next >= groups.value.length) return;

  const reordered = [...groups.value];
  [reordered[groupIndex], reordered[next]] = [reordered[next], reordered[groupIndex]];
  store.setSubAttributeGroups(props.parentIndex, reordered);
};

const onAddFirstGroup = () => {
  store.addSubAttributeGroup(props.parentIndex);
  store.addSubAttribute(props.parentIndex, 0);
};
</script>

<template>
  <tr v-if="showValueOverrideNotice" class="adt-sub-attribute-row adt-sub-attribute-row--notice">
    <td class="adt-fm-cell-check"></td>
    <td colspan="4">
      <p class="adt-sub-attribute-notice">
        <span class="adt-tw-icon-[lucide--info] adt-tw-size-4" aria-hidden="true"></span>
        {{
          __(
            'While this attribute has sub-attributes, its own mapped value is not written to the feed. The sub-attributes are the value.',
            'woo-product-feed-pro'
          )
        }}
      </p>
    </td>
  </tr>

  <template v-if="groups.length > 0">
    <template v-for="(group, groupIndex) in groups" :key="groupIndex">
      <tr v-if="showGroupChrome" class="adt-sub-attribute-row adt-sub-attribute-row--group-head">
        <td class="adt-fm-cell-check"></td>
        <!--
          The flex layout goes on a wrapper, never on the <td>: a table cell with
          `display: flex` stops honouring `colspan`, which collapsed this header
          to the width of one column and pulled its controls in with it.
        -->
        <td colspan="4">
          <div class="adt-sub-attribute-group-head-inner">
            <span class="adt-sub-attribute-group-label">{{ groupLabel(groupIndex) }}</span>
            <span class="adt-sub-attribute-group-tools">
            <button
              type="button"
              class="adt-fm-icon-btn"
              :disabled="groupIndex === 0"
              :aria-label="__('Move group up', 'woo-product-feed-pro')"
              @click="moveGroup(groupIndex, -1)"
            >
              <span class="adt-tw-icon-[lucide--chevron-up] adt-tw-size-4" aria-hidden="true"></span>
            </button>
            <button
              type="button"
              class="adt-fm-icon-btn"
              :disabled="groupIndex === groups.length - 1"
              :aria-label="__('Move group down', 'woo-product-feed-pro')"
              @click="moveGroup(groupIndex, 1)"
            >
              <span class="adt-tw-icon-[lucide--chevron-down] adt-tw-size-4" aria-hidden="true"></span>
            </button>
            <button
              type="button"
              class="adt-fm-icon-btn"
              :aria-label="sprintf(__('Remove group %d', 'woo-product-feed-pro'), groupIndex + 1)"
              @click="store.removeSubAttributeGroup(parentIndex, groupIndex)"
            >
              <span class="adt-tw-icon-[lucide--trash-2] adt-tw-size-4" aria-hidden="true"></span>
              </button>
            </span>
          </div>
        </td>
      </tr>

      <SubAttributeRow
        v-for="(sub, subIndex) in group"
        :key="`${groupIndex}-${subIndex}`"
        :parent-index="parentIndex"
        :group-index="groupIndex"
        :sub-index="subIndex"
        :sub="sub"
        :registry-tags="registryTags"
        :is-first="subIndex === 0"
      />

      <tr class="adt-sub-attribute-row adt-sub-attribute-row--add">
        <td class="adt-fm-cell-check adt-sub-attribute-gutter" aria-hidden="true">&#9492;</td>
        <td colspan="4">
          <button type="button" class="adt-sub-attribute-action" @click="store.addSubAttribute(parentIndex, groupIndex)">
            <span class="adt-tw-icon-[lucide--plus] adt-tw-size-3" aria-hidden="true"></span>
            {{ __('Add sub-attribute', 'woo-product-feed-pro') }}
          </button>

          <!-- Parent-level, so it rides the last group's closing row. -->
          <template v-if="!zonesAuto && groupIndex === groups.length - 1">
            <span class="adt-sub-attribute-action-sep" aria-hidden="true">·</span>
            <button type="button" class="adt-sub-attribute-action" @click="store.addSubAttributeGroup(parentIndex)">
              <span class="adt-tw-icon-[lucide--plus] adt-tw-size-3" aria-hidden="true"></span>
              {{ __('Add group', 'woo-product-feed-pro') }}
            </button>
          </template>
        </td>
      </tr>
    </template>
  </template>

  <tr v-else class="adt-sub-attribute-row adt-sub-attribute-row--add-group">
    <td class="adt-fm-cell-check"></td>
    <td colspan="4">
      <span class="adt-sub-attribute-empty">{{ __('No sub-attributes configured yet.', 'woo-product-feed-pro') }}</span>
      <button type="button" class="adt-sub-attribute-action" @click="onAddFirstGroup">
        <span class="adt-tw-icon-[lucide--plus-circle] adt-tw-mr-1 adt-tw-size-3" aria-hidden="true"></span>
        {{ __('Add sub-attribute', 'woo-product-feed-pro') }}
      </button>
    </td>
  </tr>
</template>
