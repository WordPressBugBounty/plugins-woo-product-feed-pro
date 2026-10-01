<script setup lang="ts">
/***************************************************************************
 * SourceControl Component
 ***************************************************************************
 *
 * The whole "Source from WooCommerce" cell in one control.
 *
 * Our data model splits a mapping into a source TYPE (field / static /
 * page_url / post_url) and a source VALUE, but showing two stacked dropdowns
 * made every row two lines tall. The legacy tab solved this years ago by
 * putting the special sources in the same dropdown as the product fields, and
 * the design does the same — so this component offers one searchable select
 * whose first group is the special sources, and reveals a second input only
 * for the cases that need one.
 *
 * One control, both surfaces: a top-level mapping row and a sub-attribute row
 * offer the same sources, so the two pickers are identical rather than merely
 * similar. `Feed_Attributes_Migrator::SUB_SOURCES` carries `page_url` and
 * `post_url` for exactly this reason.
 *
 */
import { computed, onBeforeUnmount, ref, useId, watch, h } from 'vue';
import VueSelect from 'vue-select';
import { __, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../../stores/fieldMappingStore';
import { isEliteActive, showEliteUpsellModal } from '@/helpers';
import type { AttributeSource, SubAttributeSource } from '../../types';

/** Every source either surface can hold. `field` is implicit in the catalog. */
type AnySource = AttributeSource | SubAttributeSource;



interface Option {
  value: string;
  label: string;
  group?: string;
  isGroupHeader?: boolean;
}

interface Props {
  source: AnySource;
  sourceValue: string;
  hasError?: boolean;
  /**
   * Offer the `empty` source. Sub-attribute rows only: `empty` is a member of
   * `SubAttributeSource` but NOT of `AttributeSource`, so offering it on a
   * top-level row would emit a source the parent model cannot hold.
   */
  allowEmpty?: boolean;
  /**
   * The channel's closed value set for this attribute, when it has one.
   *
   * Google accepts only a fixed list for ten of the Detailed product description
   * attributes, and a value outside it is an item-level disapproval that the feed
   * itself reports as fine. A static value is the one case the plugin can hold to
   * the list, so it becomes a picker. A product field cannot be checked here - its
   * values are per-product and unknown at mapping time - so that case gets the
   * list as guidance and nothing more.
   */
  allowedValues?: string[];
  /**
   * Values the channel's specification documents for this attribute, offered as
   * suggestions on the static input.
   *
   * A datalist, not a select: the list is what the specification says TODAY, and
   * Google adds destinations and enum values without warning. A hard select would
   * lock a store out of a value the moment the spec moves ahead of the plugin.
   */
  choices?: string[];
}

const props = withDefaults(defineProps<Props>(), {
  hasError: false,
  allowEmpty: false,
  allowedValues: () => [],
  choices: () => [],
});

const emit = defineEmits<{ (e: 'change', payload: { source: AnySource; source_value: string }): void }>();

const OpenIndicator = { render: () => h('span', { class: 'adt-tw-icon-[lucide--chevron-down]' }) };

// One id per instance: several rows render a datalist at once, and a duplicate
// id would point every input at the first list on the page.
const choicesListId = `adt-fm-choices-${useId()}`;

const store = useFieldMappingStore();

// Sentinel values for the special sources, kept out of the product-field key
// space so they can never collide with a real field key.
const STATIC = '__static';
const PAGE = '__page_url';
const POST = '__post_url';
const EMPTY = '__empty';

// These three are legacy pseudo-entries inside the product fields catalog.
// They are first-class sources here, so they are filtered out of the field
// list and offered once, at the top, instead.
const PSEUDO_FIELD_KEYS = ['static_value', 'page_url', 'post_url'];

const SPECIAL_GROUP = __('Value type', 'woo-product-feed-pro');

const isEliteGatedGroup = (group?: string) =>
  !!group && Object.prototype.hasOwnProperty.call(store.eliteGatedAttrGroups, group);

const options = computed<Option[]>(() => {
  const list: Option[] = [
    { value: `__group_${SPECIAL_GROUP}`, label: SPECIAL_GROUP, group: SPECIAL_GROUP, isGroupHeader: true },
    { value: STATIC, label: __('Static value', 'woo-product-feed-pro'), group: SPECIAL_GROUP },
    { value: PAGE, label: __('Page URL', 'woo-product-feed-pro'), group: SPECIAL_GROUP },
    { value: POST, label: __('Post URL', 'woo-product-feed-pro'), group: SPECIAL_GROUP },
  ];

  // Resolves to '' and the writer omits the element (Sub_Attribute_Resolver).
  // Offered so a seeded `empty` row can be restored after being changed away.
  if (props.allowEmpty) {
    list.push({ value: EMPTY, label: __('No value', 'woo-product-feed-pro'), group: SPECIAL_GROUP });
  }

  for (const groupName in store.productFields) {
    const attributes = store.productFields[groupName];
    const groupOptions: Option[] = [];

    for (const key in attributes) {
      if (PSEUDO_FIELD_KEYS.includes(key)) continue;
      groupOptions.push({ value: String(key), label: String(attributes[key]), group: String(groupName) });
    }

    if (groupOptions.length) {
      list.push({ value: `__group_${groupName}`, label: String(groupName), group: String(groupName), isGroupHeader: true });
      list.push(...groupOptions);
    }
  }

  return list;
});

/** The option the current source/value pair maps to. */
const selected = ref<Option | null>(null);

let suppressNextEmit = false;

const syncSelection = () => {
  if (props.source === 'static') {
    selected.value = options.value.find((o) => o.value === STATIC) ?? null;
    return;
  }
  if (props.source === 'page_url') {
    selected.value = options.value.find((o) => o.value === PAGE) ?? null;
    return;
  }
  if (props.source === 'post_url') {
    selected.value = options.value.find((o) => o.value === POST) ?? null;
    return;
  }
  if (props.source === 'empty') {
    selected.value = options.value.find((o) => o.value === EMPTY) ?? null;
    return;
  }
  selected.value = props.sourceValue
    ? options.value.find((o) => o.value === props.sourceValue && !o.isGroupHeader) ?? null
    : null;
};

watch(() => [props.source, props.sourceValue], syncSelection, { immediate: true });
watch(options, syncSelection);

const commit = (option: Option) => {
  if (!option || option.isGroupHeader) return;

  if (isEliteGatedGroup(option.group) && !isEliteActive()) {
    showEliteUpsellModal(store.eliteGatedAttrGroups[option.group as string] ?? 'default');
    suppressNextEmit = true;
    syncSelection();
    return;
  }

  if (option.value === STATIC) return emit('change', { source: 'static', source_value: '' });
  if (option.value === PAGE) return emit('change', { source: 'page_url', source_value: '' });
  if (option.value === POST) return emit('change', { source: 'post_url', source_value: '' });
  if (option.value === EMPTY) return emit('change', { source: 'empty', source_value: '' });

  emit('change', { source: 'field', source_value: option.value });
};

watch(selected, (value) => {
  if (suppressNextEmit) {
    suppressNextEmit = false;
    return;
  }
  if (value && !value.isGroupHeader) commit(value);
});

/** Match on label, keeping the group header of any group that has a hit. */
const customFilter = (list: Option[], search: string) => {
  if (!search) return list;

  const term = search.toLowerCase();
  const byGroup = new Map<string, Option[]>();
  const headers = new Map<string, Option>();

  list.forEach((option) => {
    if (option.isGroupHeader) {
      headers.set(option.group ?? '', option);
    } else if (option.group) {
      if (!byGroup.has(option.group)) byGroup.set(option.group, []);
      (byGroup.get(option.group) as Option[]).push(option);
    }
  });

  const out: Option[] = [];
  byGroup.forEach((groupOptions, groupName) => {
    const hits = groupOptions.filter((o) => o.label.toLowerCase().includes(term));
    if (!hits.length) return;
    const header = headers.get(groupName);
    if (header) out.push(header);
    out.push(...hits);
  });

  return out;
};

/** Free-text box, for a fixed value. */
const hasAllowedValues = computed(() => props.allowedValues.length > 0);

/** A closed set replaces the free-text box with a picker. */
const showStaticPicker = computed(() => props.source === 'static' && hasAllowedValues.value);

const showStaticInput = computed(() => props.source === 'static' && !hasAllowedValues.value);

/** The list is guidance on a field source: show it, but do not constrain the field. */
const showAllowedValuesHint = computed(() => props.source === 'field' && hasAllowedValues.value);

const allowedOptions = computed<Option[]>(() => props.allowedValues.map((value) => ({ value, label: value })));

/**
 * A feed saved before this picker existed can hold a value the channel rejects.
 * Surface it as the current choice rather than dropping it, so the store sees
 * what it has; the row's validation error says why it must change.
 */
const allowedSelected = computed<Option | null>(() =>
  props.sourceValue ? { value: props.sourceValue, label: props.sourceValue } : null
);

const onAllowedPick = (option: Option | null) => {
  if (option) emit('change', { source: 'static', source_value: option.value });
};

/*
 * Accessible naming for vue-select's search input.
 *
 * vue-select's root is a plain `<div class="v-select">` that declares no aria
 * props, so an `aria-label` set on the component falls through to that
 * non-interactive wrapper and is never announced. The real control is the nested
 * search input, which the component labels with `aria-labelledby` pointing at the
 * toggle's hardcoded "Search for option". `aria-labelledby` outranks `aria-label`,
 * so the `#search` slot is the only place a label or a description reaches it.
 */
const hintId = `${useId()}-allowed-values`;

const searchAttrs = (attributes: Record<string, unknown>, label?: string, describedBy?: string) => ({
  ...attributes,
  // Our own label only lands once the component's `aria-labelledby` is gone.
  ...(label ? { 'aria-labelledby': undefined, 'aria-label': label } : {}),
  ...(describedBy ? { 'aria-describedby': describedBy } : {}),
});

/** Object picker, for a page or post permalink. `empty` needs neither. */
const needsSecondary = computed(() => props.source === 'page_url' || props.source === 'post_url');

const objectKind = computed<'page' | 'post'>(() => (props.source === 'page_url' ? 'page' : 'post'));

// Options are fetched, not shipped: inlining every published page and post cost
// 399 KB on a store with 10,000 posts, for a control most rows never use.
const objectOptions = ref<Option[]>([]);
const objectLoading = ref(false);
let objectTimer: ReturnType<typeof setTimeout> | undefined;

/*
 * The debounce only collapses bursts inside its window. Type "goo", pause, type
 * "gle", and two requests are in flight with no ordering guarantee — whichever
 * answers last wins the list. A monotonic token drops an answer that a newer
 * search has already superseded, which matters here because picking the wrong
 * entry writes a real mapping value.
 */
let objectRequestId = 0;

const fetchObjects = async (term: string) => {
  const token = ++objectRequestId;
  objectLoading.value = true;

  const results = await store.searchObjects(objectKind.value, term);
  if (token !== objectRequestId) {
    return;
  }

  objectOptions.value = results;
  objectLoading.value = false;
};

const onObjectSearch = (term: string, loading: (state: boolean) => void) => {
  clearTimeout(objectTimer);
  loading(true);
  objectTimer = setTimeout(async () => {
    await fetchObjects(term);
    loading(false);
  }, 300);
};

// Prime the list the first time a page/post row needs it.
watch(
  needsSecondary,
  (needed) => {
    if (needed && objectOptions.value.length === 0) fetchObjects('');
  },
  { immediate: true }
);

// The stored value is an ID; its title comes from the store's cache, which the
// app fills for every saved page/post row on load.
const secondarySelected = computed<Option | null>(() => {
  if (!props.sourceValue) return null;

  const cached = store.objectTitles[`${objectKind.value}:${props.sourceValue}`];

  return {
    value: props.sourceValue,
    label: cached || `#${props.sourceValue}`,
  };
});

const onObjectPick = (option: Option | null) => {
  if (option) emit('change', { source: props.source, source_value: option.value });
};

onBeforeUnmount(() => clearTimeout(objectTimer));

const onStaticInput = (event: Event) => {
  emit('change', { source: 'static', source_value: (event.target as HTMLInputElement).value });
};
</script>

<template>
  <div class="adt-fm-source-control">
    <div class="adt-source-value-select-container">
      <vue-select
        v-model="selected"
        :options="options"
        :selectable="(option: any) => !option.isGroupHeader"
        :filter="customFilter"
        :placeholder="__('Select a source', 'woo-product-feed-pro')"
        :components="{ OpenIndicator }"
        :clearable="false"
        :class="{ 'adt-source-value-select-error': hasError }"
        @option:selected="commit"
      >
        <!-- vue-select renders the chosen value as bare text, so a long source
             name ellipsises with no way to read it. Give it a hover title. -->
        <template #selected-option="{ label }">
          <span class="adt-fm-selected-label" :title="label">{{ label }}</span>
        </template>

        <template #option="{ label, isGroupHeader, group }">
          <div v-if="isGroupHeader" class="adt-grouped-select-group-header">{{ label }}</div>
          <div
            v-else
            class="adt-grouped-select-option"
            :class="{ 'adt-grouped-select-option--elite-gated': isEliteGatedGroup(group) && !isEliteActive() }"
          >
            {{ label }}
          </div>
        </template>

        <!-- Ties the accepted-values hint below to the control it describes. -->
        <template #search="{ attributes, events }">
          <input
            class="vs__search"
            v-bind="searchAttrs(attributes, undefined, showAllowedValuesHint ? hintId : undefined)"
            v-on="events"
          />
        </template>
      </vue-select>
    </div>

    <div v-if="showStaticPicker" class="adt-source-value-select-container adt-fm-source-secondary">
      <vue-select
        :model-value="allowedSelected"
        :options="allowedOptions"
        :placeholder="__('Select a value', 'woo-product-feed-pro')"
        :components="{ OpenIndicator }"
        :clearable="false"
        :class="{ 'adt-source-value-select-error': hasError }"
        @update:model-value="onAllowedPick"
      >
        <template #selected-option="{ label }">
          <span class="adt-fm-selected-label" :title="label">{{ label }}</span>
        </template>
        <template #option="{ label }">
          <div class="adt-fm-plain-option" :title="label">{{ label }}</div>
        </template>
        <template #search="{ attributes, events }">
          <input
            class="vs__search"
            v-bind="searchAttrs(attributes, __('Accepted value', 'woo-product-feed-pro'))"
            v-on="events"
          />
        </template>
      </vue-select>
    </div>

    <!-- The input and its datalist are one branch: chaining `v-else-if` off the
         datalist instead would tie the object picker to the datalist's condition. -->
    <template v-else-if="showStaticInput">
      <input
        type="text"
        class="adt-fm-input adt-fm-source-secondary"
        :value="sourceValue"
        :list="choices.length > 0 ? choicesListId : undefined"
        :placeholder="choices.length > 0 ? __('Choose or type a value', 'woo-product-feed-pro') : __('Enter a fixed value', 'woo-product-feed-pro')"
        :aria-label="__('Static value', 'woo-product-feed-pro')"
        @input="onStaticInput"
      />
      <datalist v-if="choices.length > 0" :id="choicesListId">
        <option v-for="choice in choices" :key="choice" :value="choice"></option>
      </datalist>
    </template>

    <div v-else-if="needsSecondary" class="adt-source-value-select-container adt-fm-source-secondary">
      <vue-select
        :model-value="secondarySelected"
        :options="objectOptions"
        :filterable="false"
        :loading="objectLoading"
        :placeholder="source === 'page_url' ? __('Search pages…', 'woo-product-feed-pro') : __('Search posts…', 'woo-product-feed-pro')"
        :components="{ OpenIndicator }"
        :clearable="false"
        @search="onObjectSearch"
        @update:model-value="onObjectPick"
      >
        <template #selected-option="{ label }">
          <span class="adt-fm-selected-label" :title="label">{{ label }}</span>
        </template>
        <template #option="{ label }">
          <div class="adt-fm-plain-option" :title="label">{{ label }}</div>
        </template>
        <template #no-options>
          <div class="adt-fm-plain-option">
            {{ source === 'page_url' ? __('No pages match.', 'woo-product-feed-pro') : __('No posts match.', 'woo-product-feed-pro') }}
          </div>
        </template>
      </vue-select>
    </div>

    <!-- Guidance only. The field's values are per-product, so this cannot be a
         constraint - it tells the store what the field has to produce. -->
    <p v-if="showAllowedValuesHint" :id="hintId" class="adt-fm-allowed-values-hint">
      {{ sprintf(
        /* translators: %s: comma-separated list of the values the channel accepts. */
        __('Accepted values: %s', 'woo-product-feed-pro'),
        allowedValues.join(', ')
      ) }}
    </p>
  </div>
</template>
