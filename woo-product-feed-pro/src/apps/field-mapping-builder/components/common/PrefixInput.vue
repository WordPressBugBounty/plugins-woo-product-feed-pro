<script setup lang="ts">
/***************************************************************************
 * PrefixInput Component
 ***************************************************************************
 *
 * Free-text prefix input for a mapping row.
 *
 */
import { __ } from '@wordpress/i18n';

defineProps<{ modelValue: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const onInput = (event: Event) => {
  emit('update:modelValue', (event.target as HTMLInputElement).value);
};
</script>

<template>
  <input
    type="text"
    class="adt-prefix-input adt-tw-w-full adt-tw-max-w-[120px] adt-tw-h-[30px] adt-tw-rounded-md adt-tw-border adt-tw-border-gray-300 adt-tw-text-sm adt-tw-px-2"
    :value="modelValue"
    :placeholder="__('Prefix', 'woo-product-feed-pro')"
    :aria-label="__('Prefix', 'woo-product-feed-pro')"
    @input="onInput"
  />
</template>
