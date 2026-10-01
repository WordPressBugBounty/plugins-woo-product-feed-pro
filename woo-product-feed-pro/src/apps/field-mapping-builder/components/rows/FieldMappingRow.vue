<script setup lang="ts">
/***************************************************************************
 * FieldMappingRow Component
 ***************************************************************************
 *
 * One row of the mapping table, driven by a `SpecRow` rather than by a stored
 * attribute. The table renders the channel's whole catalog, so a row may be
 * INERT — visible, with its requirement badge and its place in the group, but
 * with no entry in `feedAttributes` and therefore nothing in the saved payload
 * or the generated feed. Picking a source is what materialises the entry;
 * clearing it again removes the entry and returns the row to inert.
 *
 * The expanded area hosts both the transform editor and, for nested parents,
 * the sub-attribute panel from #1040.
 *
 */
import { computed, ref } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../../stores/fieldMappingStore';
import SourceControl from '../common/SourceControl.vue';
import AffixControl from '../common/AffixControl.vue';
import SubAttributePanel from '../sub-attributes/SubAttributePanel.vue';
import RepeatPanel from '../repeats/RepeatPanel.vue';
import type { AttributeSource, SpecRow, SubAttributeSource } from '../../types';

interface Props {
  row: SpecRow;
  selected: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{ (e: 'update:selected', value: boolean): void }>();

const store = useFieldMappingStore();

const expanded = ref(false);

/** The stored attribute behind this row, or null while the row is inert. */
const attribute = computed(() =>
  props.row.attrIndex !== null ? store.feedAttributes[props.row.attrIndex] ?? null : null
);

const isMapped = computed(() => store.isRowMapped(props.row));

const errorKey = computed(() => `attribute-${props.row.attrIndex}`);

const errors = computed(() =>
  props.row.attrIndex !== null ? store.getFieldErrors(errorKey.value) : []
);

/** Row status, driving the dot colour and the row tint. */
const status = computed(() => {
  if (!isMapped.value) {
    return props.row.format === 'required' ? 'warn' : 'gray';
  }
  if (props.row.isCustom) return 'pink';
  if (attribute.value?.source === 'static') return 'violet';
  return 'ok';
});

const source = computed<AttributeSource>(() => attribute.value?.source ?? 'field');
const sourceValue = computed(() => attribute.value?.source_value ?? '');

/** Whether the registry supplies default sub-attributes for this output tag. */
const hasRegistryDefaults = computed(() => store.getRegistryTags(props.row.feedName).size > 0);

/** Whether this row already carries sub-attribute groups. */
const hasSubAttributes = computed(() => !!attribute.value?.sub_attributes?.length);

/**
 * Whether the channel declares this attribute repeatable.
 *
 * A repeatable attribute and a nested one both configure themselves through
 * `sub_attributes`, and the two shapes cannot be mixed on one row, so the
 * expander offers one panel or the other — never both. Cardinality wins: the
 * channel declared it, whereas nesting is offered on every row.
 */
const isRepeatable = computed(() => props.row.repeatMax > 1);

/**
 * Whether this row is configured as a NESTED parent instead of a repeating one.
 *
 * A repeatable tag can also carry tagged sub-attribute groups — the "Add
 * sub-attributes" path allows it on any row — and the two shapes share
 * `sub_attributes`, so they cannot be mixed. When a row is in that state the
 * sub-attribute panel keeps it, so the stored groups stay visible and editable;
 * the repeat panel stands aside rather than showing an empty list and an "Add
 * value" action the store refuses.
 */
const isNestedParent = computed(() =>
  props.row.attrIndex !== null ? store.isNestedParent(props.row.attrIndex) : false
);

/**
 * Nesting is a property of the schema, not of the attribute.
 *
 * ANY output tag can carry sub-attributes — the epic's Definition of Done asks
 * for exactly that, and nothing downstream disagrees: `Sub_Attribute_Resolver`
 * resolves any row with a non-empty `sub_attributes`, `Feed_Writer_XML` writes
 * any array payload through `write_nested()`, and the schema is per-row and
 * unconstrained. The registry only supplies DEFAULTS.
 *
 * So the expander is offered on every MAPPED row, plus registry parents while
 * still inert (they advertise nesting before a source is picked). Inert rows
 * with no registry entry get none, which keeps the chevron off the long tail of
 * an unmapped catalog — 133 rows on Google Shopping, of which a new feed
 * pre-populates 16.
 */
const canExpand = computed(
  () => isMapped.value || hasRegistryDefaults.value || hasSubAttributes.value || isRepeatable.value
);

/**
 * The panel renders once the row is materialised and there is something to show
 * — configured groups, or registry defaults whose empty state offers them.
 */
const showSubAttributePanel = computed(
  () =>
    (!isRepeatable.value || isNestedParent.value) &&
    !!attribute.value &&
    (hasSubAttributes.value || hasRegistryDefaults.value)
);

/**
 * The repeat panel renders once the row exists, so a user can add value two
 * without first having to pick value one.
 */
const showRepeatPanel = computed(() => isRepeatable.value && !isNestedParent.value && !!attribute.value);

const toggleLabel = computed(() =>
  sprintf(
    /* translators: %s: attribute output tag */
    __('Toggle details for %s', 'woo-product-feed-pro'),
    props.row.feedName || props.row.label
  )
);

/**
 * Materialise the row on first edit, then apply the change.
 */
const withAttribute = (updates: Record<string, unknown>) => {
  const index = props.row.attrIndex !== null ? props.row.attrIndex : store.mapSpecRow(props.row);
  store.updateAttribute(index, updates);
  return index;
};

const onSourceChange = (payload: { source: AttributeSource | SubAttributeSource; source_value: string }) => {
  /*
   * `SourceControl` is shared with the sub-attribute row, so its payload covers
   * both source unions. This surface does not offer `empty`, and never `zone`
   * (see the `special-sources` binding below), so narrowing here is safe.
   */
  const update = { source: payload.source as AttributeSource, source_value: payload.source_value };

  // First edit on an inert row materialises it, then seeds any registry
  // defaults so a nested parent opens ready to configure.
  if (props.row.attrIndex === null) {
    const index = store.mapSpecRow(props.row, update);
    store.applyRegistryDefaults(index);
    if (store.feedAttributes[index]?.sub_attributes?.length) {
      expanded.value = true;
    }
    return;
  }

  store.updateAttribute(props.row.attrIndex, update);
};

const onAffixChange = (payload: { prefix: string; suffix: string }) => {
  if (props.row.attrIndex === null) return;
  store.updateAttribute(props.row.attrIndex, payload);
};

const onCustomNameInput = (event: Event) => {
  const value = (event.target as HTMLInputElement).value;
  if (props.row.attrIndex === null) return;
  store.changeAttributeFeedName(props.row.attrIndex, value);
};

/**
 * Materialise a nested parent and seed its registry defaults.
 *
 * Only ever runs from the explicit button, so simply expanding a row never
 * changes the saved mapping.
 */
const configureSubAttributes = () => {
  const index = props.row.attrIndex !== null ? props.row.attrIndex : store.mapSpecRow(props.row);
  store.applyRegistryDefaults(index);
};

/**
 * Start a sub-attribute group on a row the registry knows nothing about.
 *
 * `applyRegistryDefaults()` is a no-op without registry entries, so an
 * arbitrary parent needs an explicit empty group plus one blank row — the same
 * pair `SubAttributePanel`'s own empty state creates.
 */
const addFirstSubAttributeGroup = () => {
  const index = props.row.attrIndex !== null ? props.row.attrIndex : store.mapSpecRow(props.row);
  store.addSubAttributeGroup(index);
  store.addSubAttribute(index, 0);
};

/**
 * Materialise the row so the repeat panel has somewhere to write.
 *
 * Only ever runs from the explicit button, so simply expanding a repeatable row
 * never changes the saved mapping.
 */
const configureRepeats = () => {
  const index = props.row.attrIndex !== null ? props.row.attrIndex : store.mapSpecRow(props.row);
  store.addRepeat(index, props.row.repeatMax);
};

const onRemove = () => {
  if (props.row.attrIndex === null) return;
  store.removeAttribute(props.row.attrIndex);
};
</script>

<template>
  <tr class="adt-fm-row" :data-row-key="row.key" :class="[`adt-fm-row--${status}`, `adt-fm-row--${store.density}`, { 'adt-fm-row--inert': !isMapped }]">
    <td class="adt-fm-cell-check">
      <input
        v-if="row.attrIndex !== null"
        type="checkbox"
        name="record"
        :checked="selected"
        :aria-label="sprintf(__('Select %s for deletion', 'woo-product-feed-pro'), row.label)"
        @change="emit('update:selected', ($event.target as HTMLInputElement).checked)"
      />
    </td>

    <td class="adt-fm-cell-attr">
      <div class="adt-fm-attr-main">
        <span class="adt-fm-dot" :class="`adt-fm-dot-${status}`" aria-hidden="true"></span>

        <input
          v-if="row.isCustom"
          type="text"
          class="adt-fm-input adt-fm-input-name"
          :class="{ 'adt-fm-input-error': errors.length > 0 }"
          :value="row.feedName"
          :placeholder="__('Custom field name', 'woo-product-feed-pro')"
          :aria-label="__('Custom field name', 'woo-product-feed-pro')"
          :aria-invalid="errors.length > 0"
          :aria-describedby="errors.length > 0 ? `${errorKey}-message` : undefined"
          @input="onCustomNameInput"
        />
        <span v-else class="adt-fm-attr-label" :title="row.label">{{ row.label }}</span>

        <span v-if="row.format === 'required'" class="adt-fm-pill adt-fm-pill-required">
          {{ __('Required', 'woo-product-feed-pro') }}
        </span>
        <span v-else-if="row.format === 'recommended'" class="adt-fm-pill adt-fm-pill-recommended">
          {{ __('Recommended', 'woo-product-feed-pro') }}
        </span>

        <!--
          The expander for the row's details: transform editor and
          sub-attributes. The transform chip opens the same area, but it reads as
          "add a prefix", so nesting needs an entry point that does not hide
          behind it. Inert non-registry rows have nothing to expand.
        -->
        <button
          v-if="canExpand"
          type="button"
          class="adt-fm-icon-btn adt-fm-row-toggle"
          :aria-expanded="expanded"
          :aria-label="toggleLabel"
          @click="expanded = !expanded"
        >
          <span
            class="adt-tw-size-4"
            :class="expanded ? 'adt-tw-icon-[lucide--chevron-up]' : 'adt-tw-icon-[lucide--chevron-down]'"
            aria-hidden="true"
          ></span>
        </button>
      </div>
      <div class="adt-fm-attr-key">{{ row.feedName }}</div>

      <!--
        The channel's explanation for a conditional requirement, present only
        while that requirement is armed. It sits on the row it explains, so the
        badge that just appeared and the reason for it are read together.
      -->
      <p v-if="row.requirementNotice" class="adt-fm-requirement-notice">
        <span class="adt-tw-icon-[lucide--info] adt-tw-size-4 adt-tw-shrink-0" aria-hidden="true"></span>
        <span>{{ row.requirementNotice }}</span>
      </p>

      <p v-if="errors.length > 0" :id="`${errorKey}-message`" class="adt-field-error adt-fm-error">{{ errors[0] }}</p>
    </td>

    <td class="adt-fm-cell-transform">
      <AffixControl
        v-if="attribute"
        :prefix="attribute.prefix"
        :suffix="attribute.suffix"
        :row-label="row.feedName || row.label"
        @change="onAffixChange"
      />
    </td>

    <td class="adt-fm-cell-source">
      <!-- The row's only validation error is a source error, so it belongs here. -->
      <SourceControl
        :source="source"
        :source-value="sourceValue"
        :has-error="errors.length > 0"
        :allowed-values="row.allowedValues"
        :choices="row.choices"
        @change="onSourceChange"
      />
    </td>

    <td class="adt-fm-cell-actions">
      <button
        v-if="row.attrIndex !== null"
        type="button"
        class="adt-fm-icon-btn"
        :aria-label="sprintf(__('Remove mapping for %s', 'woo-product-feed-pro'), row.label)"
        @click="onRemove"
      >
        <span class="adt-tw-icon-[lucide--x] adt-tw-size-4" aria-hidden="true"></span>
      </button>
    </td>
  </tr>

  <tr v-if="expanded" class="adt-fm-row-expand">
    <td></td>
    <td colspan="4">
      <!--
        A registry parent that is still inert. Expanding must not quietly write
        to the mapping, so the row is materialised only when the user asks for
        it, and then seeded with the channel's defaults.
      -->
      <!--
        A repeatable attribute that is still inert. Same rule as the nested CTA
        below: expanding must not quietly write to the mapping.
      -->
      <div v-if="isRepeatable && !isNestedParent && !showRepeatPanel" class="adt-fm-nested-cta">
        <p>
          {{
            sprintf(
              /* translators: %d: maximum number of values the channel allows */
              __('This attribute can be sent up to %d times. Each value is written as its own element.', 'woo-product-feed-pro'),
              row.repeatMax
            )
          }}
        </p>
        <button type="button" class="adt-button adt-button-sm" @click="configureRepeats">
          <span class="adt-tw-icon-[lucide--plus] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
          {{ __('Add another value', 'woo-product-feed-pro') }}
        </button>
      </div>

      <div v-else-if="hasRegistryDefaults && !showSubAttributePanel" class="adt-fm-nested-cta">
        <p>{{ __('This attribute supports sub-attributes, such as the parts of a shipping or tax group.', 'woo-product-feed-pro') }}</p>
        <button type="button" class="adt-button adt-button-sm" @click="configureSubAttributes">
          <span class="adt-tw-icon-[lucide--plus] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
          {{ __('Configure sub-attributes', 'woo-product-feed-pro') }}
        </button>
      </div>

      <!--
        Any other mapped row. The registry has no defaults to offer, so this
        starts an empty group and every sub-attribute tag is free text.
      -->
      <div v-else-if="attribute && !showSubAttributePanel && (!isRepeatable || isNestedParent)" class="adt-fm-nested-add">
        <button type="button" class="adt-button adt-button-sm" @click="addFirstSubAttributeGroup">
          <span class="adt-tw-icon-[lucide--plus] adt-tw-size-4" aria-hidden="true"></span>
          {{ __('Add sub-attributes', 'woo-product-feed-pro') }}
        </button>
      </div>
    </td>
  </tr>

  <!--
    Sub-attribute rows are siblings, not cell content: they use the table's own
    columns so every nested control lines up with the parent's.
  -->
  <SubAttributePanel
    v-if="expanded && showSubAttributePanel && row.attrIndex !== null && attribute"
    :parent-index="row.attrIndex"
    :attribute="attribute"
  />

  <RepeatPanel v-if="expanded && showRepeatPanel && row.attrIndex !== null" :row="row" :parent-index="row.attrIndex" />
</template>
