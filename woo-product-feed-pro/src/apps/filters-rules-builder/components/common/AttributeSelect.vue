<script setup lang="ts">
import { computed, ref, watch, h } from 'vue'
import VueSelect from 'vue-select'
import { useFiltersStore } from '../../stores/filtersStore'
import { useRulesStore } from '../../stores/rulesStore'
import { isEliteActive, showEliteUpsellModal } from '@/helpers'

interface AttributeOption {
  value: string
  label: string
  group?: string
  isGroupHeader?: boolean
}

interface Props {
  modelValue?: string
  placeholder?: string
  hasError?: boolean
  storeType?: 'filters' | 'rules'
  isThenAttributes?: boolean
}

interface Emits {
  (e: 'update:modelValue', value: string): void
  (e: 'change', value: string): void
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Select attribute',
  hasError: false,
  storeType: 'filters'
})

const emit = defineEmits<Emits>()

// Custom OpenIndicator component for dropdown arrow
const OpenIndicator = {
  render: () => h('span', {
    class: 'adt-tw-icon-[lucide--chevron-down]'
  })
}

const filtersStore = useFiltersStore()
const rulesStore = useRulesStore()

const selectedAttribute = ref<AttributeOption | null>(null)

// Suppress the next emit when we reset `selectedAttribute` after intercepting an Elite-gated click.
// Without this, the watcher below re-fires `update:modelValue` with the reverted value, which is
// harmless on the parent (idempotent) but pollutes the change stream and triggers validation reruns.
let suppressNextEmit = false

// Map of Elite-gated group label -> upsell modal key, shipped by the server.
// Only Rules-side dropdowns receive entries today (Pro registers the filter on `type=rules` /
// `type=rules_then` only), so when this component renders a Filters dropdown the map is empty
// and no gating logic runs.
const eliteGatedGroups = computed<Record<string, string>>(() =>
  props.storeType === 'rules' ? rulesStore.eliteGatedAttrGroups : {}
)

const isEliteGatedGroup = (groupName: string | undefined): boolean => {
  if (!groupName) return false
  return Object.prototype.hasOwnProperty.call(eliteGatedGroups.value, groupName)
}

const getEliteUpsellModalKey = (groupName: string | undefined): string => {
  if (!groupName) return 'default'
  return eliteGatedGroups.value[groupName] ?? 'default'
}

// Convert store.attributes to vue-select options format with group information
const flattenedAttributes = computed(() => {
  const attributes = props.isThenAttributes ? rulesStore.thenAttributes : (props.storeType === 'rules' ? rulesStore.attributes : filtersStore.attributes)
  const options: Array<{ value: string; label: string; group: string; isGroupHeader?: boolean }> = [];
  let lastGroup = '';
  
  for (const groupName in attributes) {
    const groupAttrs = attributes[groupName];
    
    // Add group header
    if (lastGroup !== groupName) {
      options.push({
        value: `__group_${groupName}`,
        label: String(groupName),
        group: String(groupName),
        isGroupHeader: true,
      });
      lastGroup = groupName;
    }
    
    // Add group options
    for (const attr in groupAttrs) {
      options.push({
        value: String(attr),
        label: String(groupAttrs[attr]),
        group: String(groupName),
      });
    }
  }
  
  return options;
})

// Initialize selected attribute based on modelValue
watch(
  () => props.modelValue,
  (newValue) => {
    if (newValue && flattenedAttributes.value.length > 0) {
      const found = flattenedAttributes.value.find(attr => attr.value === newValue && !attr.isGroupHeader)
      selectedAttribute.value = found || null
    } else {
      selectedAttribute.value = null
    }
  },
  { immediate: true }
)

// Watch for changes in flattened attributes to update selection
watch(
  flattenedAttributes,
  () => {
    if (props.modelValue && flattenedAttributes.value.length > 0) {
      const found = flattenedAttributes.value.find(attr => attr.value === props.modelValue && !attr.isGroupHeader)
      selectedAttribute.value = found || null
    }
  },
  { immediate: true }
)

const onAttributeSelected = (option: AttributeOption) => {
  if (!option || option.isGroupHeader) return

  // Pro upsell: if this option belongs to an Elite-gated group AND Elite is not active,
  // fire the upsell modal and revert the select to its previous value instead of committing.
  if (isEliteGatedGroup(option.group) && !isEliteActive()) {
    showEliteUpsellModal(getEliteUpsellModalKey(option.group))

    suppressNextEmit = true
    const previous = props.modelValue
      ? flattenedAttributes.value.find(a => a.value === props.modelValue && !a.isGroupHeader) ?? null
      : null
    selectedAttribute.value = previous
    return
  }

  emit('update:modelValue', option.value)
  emit('change', option.value)
}

// Custom filter function for vue-select
const customFilter = (options: AttributeOption[], search: string) => {
  if (!search) return options
  
  const filtered: AttributeOption[] = []
  const lowerSearch = search.toLowerCase()
  
  // Group options by their group
  const groupMap = new Map<string, AttributeOption[]>()
  const groupHeaders = new Map<string, AttributeOption>()
  
  options.forEach(option => {
    if (option.isGroupHeader) {
      groupHeaders.set(option.group || '', option)
    } else if (option.group) {
      if (!groupMap.has(option.group)) {
        groupMap.set(option.group, [])
      }
      groupMap.get(option.group)!.push(option)
    }
  })
  
  // Filter and include groups with matching options
  groupMap.forEach((groupOptions, groupName) => {
    const matchingOptions = groupOptions.filter(option => 
      option.label.toLowerCase().includes(lowerSearch)
    )
    
    if (matchingOptions.length > 0) {
      // Add group header if it exists
      const header = groupHeaders.get(groupName)
      if (header) {
        filtered.push(header)
      }
      // Add matching options
      filtered.push(...matchingOptions)
    }
  })
  
  return filtered
}

// Watch selectedAttribute to emit changes
watch(selectedAttribute, (newValue) => {
  if (suppressNextEmit) {
    suppressNextEmit = false
    return
  }
  if (newValue && !newValue.isGroupHeader) {
    if (isEliteGatedGroup(newValue.group) && !isEliteActive()) {
      // Defense-in-depth: never emit a value from an Elite-gated group when Elite is inactive.
      // `onAttributeSelected` already intercepts the primary click path; this guards programmatic
      // paths (modelValue assignment, sync watchers, dev console) from leaking gated values upstream.
      // Intentional divergence: if a parent pushes a gated value via :modelValue, the dropdown will
      // visibly show the gated label as "selected" while the parent's bound state stays at the prior
      // (non-gated) value. We never commit gated values, period — the visual mismatch is acceptable.
      return
    }
    emit('update:modelValue', newValue.value)
    emit('change', newValue.value)
  }
})
</script>

<template>
  <div class="adt-attribute-select-container">
    <vue-select
      v-model="selectedAttribute"
      :options="flattenedAttributes"
      :selectable="(option: any) => !option.isGroupHeader"
      :filter="customFilter"
      :placeholder="placeholder"
      :components="{ OpenIndicator }"
      :clearable="false"
      class="adt-attribute-select"
      :class="{ 'adt-attribute-select-error': hasError }"
      @option:selected="onAttributeSelected"
    >
      <template #option="{ label, isGroupHeader, group }">
        <div
          v-if="isGroupHeader"
          class="adt-attribute-select-group-header"
        >
          {{ label }}
        </div>
        <div
          v-else
          class="adt-attribute-select-option"
          :class="{ 'adt-attribute-select-option--elite-gated': isEliteGatedGroup(group) && !isEliteActive() }"
        >
          {{ label }}
        </div>
      </template>
    </vue-select>
  </div>
</template>

<style scoped>
.adt-attribute-select-container {
  position: relative;
}

.adt-attribute-select {
  width: 100%;
}

.adt-attribute-select-group-header {
  font-weight: bold;
  color: #1f2937;
  background-color: #f8f9fa;
  border-bottom: 1px solid #e9ecef;
  border-top: 1px solid #e9ecef;
  padding: 0.25rem 0.5rem;
}

.adt-attribute-select-option {
  padding: 0.25rem 1.5rem;
}

.adt-attribute-select-option--elite-gated {
  opacity: 0.55;
  font-style: italic;
}

.adt-attribute-select-container .adt-attribute-select-error :deep(.vs__dropdown-toggle) {
  border-color: #dc3545;
}

/* Vue Select styling to match other form fields */
.adt-attribute-select-container :deep(.vs__dropdown-toggle) {
  padding: 0;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  min-height: 1.875rem; /* 30px height to match other inputs */
  height: 1.875rem;
  box-sizing: border-box;
  transition: all 0.15s ease-in-out;
}

.adt-attribute-select-container :deep(.vs__dropdown-toggle):focus-within {
  outline: 2px solid transparent;
  box-shadow: 0 0 0 1px #2271b1;
  border-color: #2271b1;
}

.adt-attribute-select-container :deep(.vs__selected-options) {
  padding: 0.25rem 0.5rem;
  flex-wrap: nowrap;
  min-height: auto;
}

.adt-attribute-select-container :deep(.vs__search) {
  border: none;
  margin: 0;
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  height: auto;
  min-height: auto;
}

.adt-attribute-select-container :deep(.vs__search:focus) {
  margin: 0;
  box-shadow: none;
  border: none;
  outline: none;
}

.adt-attribute-select-container :deep(.vs__search::placeholder) {
  color: #9ca3af;
}

.adt-attribute-select-container :deep(.vs__actions) {
  padding: 0.125rem 0.25rem;
}

.adt-attribute-select-container :deep(.vs__clear) {
  fill: #6b7280;
  width: 1rem;
  height: 1rem;
}

.adt-attribute-select-container :deep(.vs__open-indicator) {
  fill: #6b7280;
  width: 1rem;
  height: 1rem;
}

.adt-attribute-select-container :deep(.vs__dropdown-menu) {
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
  z-index: 1000;
}

.adt-attribute-select-container :deep(.vs__dropdown-option) {
  font-size: 0.875rem;
  line-height: 1.25rem;
  padding: 0;
  white-space: normal;
}

.adt-attribute-select-container :deep(.vs__dropdown-option--highlight) {
  background-color: #3b82f6;
  color: white;
}

.adt-attribute-select-container :deep(.vs__selected) {
  margin: 0;
  padding: 0;
  border: none;
  background-color: transparent;
  color: inherit;
  font-size: 0.875rem;
  line-height: 1.25rem;
}
</style> 
