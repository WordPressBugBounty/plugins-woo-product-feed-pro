<script setup lang="ts">
import { __ } from '@wordpress/i18n';
import type { RuleActionSegment } from '../../stores/rulesStore';
import AttributeSelect from './AttributeSelect.vue';

interface Props {
  modelValue?: RuleActionSegment[];
  hasError?: boolean;
}

interface Emits {
  (e: 'update:modelValue', value: RuleActionSegment[]): void;
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => [],
  hasError: false,
});

const emit = defineEmits<Emits>();

const inputClass =
  'adt-tw-flex-1 adt-tw-min-w-0 adt-tw-px-2 adt-tw-py-1 adt-tw-border adt-tw-border-gray-300 adt-tw-rounded-md adt-tw-text-sm adt-tw-focus-ring-2 adt-tw-focus-ring-blue-500 adt-tw-focus-border-blue-500 adt-tw-focus-outline-none';

// Each segment carries a stable `id` (like rules/groups/actions elsewhere in the
// store) so the v-for keys on it: index keys would let component-internal state
// (open dropdown, search text) stick to the wrong row after a move/remove, and
// edits that replace the object preserve the id so the row isn't remounted mid-typing.
let segmentIdCounter = 0;
const createSegmentId = (): string => 'segment-' + Date.now() + '-' + (segmentIdCounter += 1);

const emitSegments = (segments: RuleActionSegment[]) => {
  emit('update:modelValue', segments);
};

const replaceSegment = (index: number, next: RuleActionSegment) => {
  const segments = [...props.modelValue];
  segments[index] = next;
  emitSegments(segments);
};

const addSegment = () => {
  emitSegments([...props.modelValue, { id: createSegmentId(), type: 'attribute', value: '' }]);
};

const removeSegment = (index: number) => {
  emitSegments(props.modelValue.filter((_, i) => i !== index));
};

const updateSegmentType = (index: number, type: string) => {
  // Reset the value on type switch — an attribute key is not static text and vice versa.
  // Fall back to a fresh id so an id-less segment can never break v-for keying.
  replaceSegment(index, {
    id: props.modelValue[index]?.id ?? createSegmentId(),
    type: type as RuleActionSegment['type'],
    value: '',
  });
};

const updateSegmentValue = (index: number, value: string) => {
  replaceSegment(index, { ...props.modelValue[index], value });
};

const moveSegment = (index: number, offset: number) => {
  const target = index + offset;
  if (target < 0 || target >= props.modelValue.length) return;

  const segments = [...props.modelValue];
  [segments[index], segments[target]] = [segments[target], segments[index]];
  emitSegments(segments);
};
</script>

<template>
  <div class="adt-combine-fields-segments adt-tw-space-y-2">
    <div
      v-for="(segment, index) in props.modelValue"
      :key="segment.id"
      class="adt-tw-flex adt-tw-items-center adt-tw-gap-2 adt-combine-fields-segment"
    >
      <select
        :value="segment.type"
        :aria-label="__('Segment type', 'woo-product-feed-pro')"
        class="adt-tw-w-28 adt-tw-shrink-0 adt-tw-px-2 adt-tw-py-1 adt-tw-border adt-tw-border-gray-300 adt-tw-rounded-md adt-tw-text-sm adt-tw-focus-ring-2 adt-tw-focus-ring-blue-500 adt-tw-focus-border-blue-500 adt-tw-focus-outline-none"
        @change="updateSegmentType(index, ($event.target as HTMLSelectElement).value)"
      >
        <option value="attribute">{{ __('Attribute', 'woo-product-feed-pro') }}</option>
        <option value="static">{{ __('Static text', 'woo-product-feed-pro') }}</option>
      </select>

      <div class="adt-tw-flex-1 adt-tw-min-w-0">
        <AttributeSelect
          v-if="segment.type === 'attribute'"
          :model-value="segment.value"
          store-type="rules"
          :has-error="props.hasError && !segment.value"
          @update:model-value="updateSegmentValue(index, $event)"
        />
        <input
          v-else
          type="text"
          :value="segment.value"
          :aria-label="__('Static text', 'woo-product-feed-pro')"
          :placeholder="__('Enter static text', 'woo-product-feed-pro')"
          :class="[inputClass, props.hasError && !segment.value ? 'adt-tw-border-red-500' : '']"
          @input="updateSegmentValue(index, ($event.target as HTMLInputElement).value)"
        />
      </div>

      <div class="adt-tw-flex adt-tw-items-center adt-tw-gap-1 adt-tw-shrink-0">
        <button
          type="button"
          class="adt-tw-bg-transparent adt-tw-border-none adt-tw-cursor-pointer adt-tw-p-0"
          :disabled="index === 0"
          :aria-label="__('Move segment up', 'woo-product-feed-pro')"
          @click="moveSegment(index, -1)"
        >
          <span
            class="adt-tw-text-sm adt-tw-icon-[lucide--arrow-up] adt-tw-transition-colors"
            :class="index === 0 ? 'adt-tw-text-gray-200' : 'adt-tw-text-gray-400 hover:adt-tw-text-blue-500'"
          ></span>
        </button>
        <button
          type="button"
          class="adt-tw-bg-transparent adt-tw-border-none adt-tw-cursor-pointer adt-tw-p-0"
          :disabled="index === props.modelValue.length - 1"
          :aria-label="__('Move segment down', 'woo-product-feed-pro')"
          @click="moveSegment(index, 1)"
        >
          <span
            class="adt-tw-text-sm adt-tw-icon-[lucide--arrow-down] adt-tw-transition-colors"
            :class="
              index === props.modelValue.length - 1
                ? 'adt-tw-text-gray-200'
                : 'adt-tw-text-gray-400 hover:adt-tw-text-blue-500'
            "
          ></span>
        </button>
        <button
          type="button"
          class="adt-tw-bg-transparent adt-tw-border-none adt-tw-cursor-pointer adt-tw-p-0"
          :aria-label="__('Remove segment', 'woo-product-feed-pro')"
          @click="removeSegment(index)"
        >
          <span
            class="adt-tw-text-sm adt-tw-icon-[lucide--trash-2] adt-tw-text-gray-400 adt-tw-transition-colors hover:adt-tw-text-red-500"
          ></span>
        </button>
      </div>
    </div>

    <button
      type="button"
      class="adt-tw-border-none adt-tw-cursor-pointer adt-tw-flex adt-tw-items-center adt-tw-px-2 adt-tw-py-1 adt-tw-bg-gray-100 adt-tw-text-gray-700 adt-tw-rounded-md hover:adt-tw-bg-gray-200 adt-tw-transition-colors adt-tw-text-xs"
      @click="addSegment"
    >
      <span class="adt-tw-icon-[lucide--plus-circle] adt-tw-mr-1"></span>
      {{ __('Add segment', 'woo-product-feed-pro') }}
    </button>
  </div>
</template>
