<script setup lang="ts">
/***************************************************************************
 * AffixControl Component
 ***************************************************************************
 *
 * The whole prefix/suffix affordance for one row: a pill that says what it
 * holds, and a compact popover that edits it.
 *
 * ONE component serves both surfaces. A parent mapping row and a sub-attribute
 * row used to treat the same two fields differently — a full-width bordered
 * panel with its own headings and a Clear button on the parent, two bare inputs
 * on a nested row — which is why they never looked related.
 *
 * The pill reads `Affix` when nothing is set and the literal value when it is
 * (`SKU-…`, `… AUD`), so a configured affix stays visible without expanding the
 * row. The editor is a 240px popover anchored to the pill rather than a panel
 * the width of the table: it edits two short strings and should not be heavier
 * than the thing it edits.
 *
 * The popover is absolutely positioned inside its table cell, which works
 * because `.adt-fm-main` deliberately does NOT clip its overflow — the same
 * reason the source dropdowns can escape the table.
 *
 */
import { computed, onBeforeUnmount, ref, watch, nextTick } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import PrefixInput from './PrefixInput.vue';
import SuffixInput from './SuffixInput.vue';
import { useAffixPopover } from '../../composables/affixPopover';


interface Props {
  prefix: string;
  suffix: string;
  /** Names the row in the control's accessible label. */
  rowLabel?: string;
}

const props = withDefaults(defineProps<Props>(), { rowLabel: '' });

const emit = defineEmits<{ (e: 'change', payload: { prefix: string; suffix: string }): void }>();

// One popover at a time, tracked in a module so the state is genuinely shared.
const popover = useAffixPopover(Symbol('affix-control'));

const open = computed({
  get: () => popover.isOpen(),
  set: (value: boolean) => popover.set(value),
});

const root = ref<HTMLElement | null>(null);
const pop = ref<HTMLElement | null>(null);

const hasAffix = computed(() => !!props.prefix || !!props.suffix);

/** The pill's own text: the affix, with an ellipsis standing in for the value. */
const label = computed(() => {
  if (props.prefix && props.suffix) return `${props.prefix}…${props.suffix}`;
  if (props.prefix) return `${props.prefix}…`;
  if (props.suffix) return `…${props.suffix}`;
  return __('Affix', 'woo-product-feed-pro');
});

const description = computed(() => {
  if (props.prefix && props.suffix) {
    /* translators: 1: the configured prefix, 2: the configured suffix */
    return sprintf(__('Prefix “%1$s” and suffix “%2$s”', 'woo-product-feed-pro'), props.prefix, props.suffix);
  }
  if (props.prefix) {
    /* translators: %s: the configured prefix */
    return sprintf(__('Prefix “%s”', 'woo-product-feed-pro'), props.prefix);
  }
  if (props.suffix) {
    /* translators: %s: the configured suffix */
    return sprintf(__('Suffix “%s”', 'woo-product-feed-pro'), props.suffix);
  }
  return props.rowLabel
    ? /* translators: %s: the row's output tag */
      sprintf(__('Add a prefix or suffix to %s', 'woo-product-feed-pro'), props.rowLabel)
    : __('Add a prefix or suffix', 'woo-product-feed-pro');
});

const onDocumentPointerDown = (event: Event) => {
  if (root.value && !root.value.contains(event.target as Node)) {
    open.value = false;
  }
};

const onDocumentKeydown = (event: KeyboardEvent) => {
  if ('Escape' === event.key) {
    open.value = false;
  }
};

const detach = () => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
  document.removeEventListener('keydown', onDocumentKeydown);
};

/*
 * Listeners exist only while a popover is open, so a table of 133 rows never
 * carries 133 idle document listeners. The outside-click listener is added in
 * this flush, which is after the opening click's own pointerdown has already
 * propagated — verified with a real mouse click, not a scripted one.
 */
watch(open, async (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    document.addEventListener('keydown', onDocumentKeydown);
    await nextTick();
    pop.value?.querySelector<HTMLInputElement>('input')?.focus();
    return;
  }

  detach();
});

onBeforeUnmount(() => {
  detach();
  // Do not leave the shared slot claimed by a control that no longer exists.
  popover.release();
});

const setPrefix = (value: string) => emit('change', { prefix: value, suffix: props.suffix });
const setSuffix = (value: string) => emit('change', { prefix: props.prefix, suffix: value });

const clear = () => {
  emit('change', { prefix: '', suffix: '' });
  open.value = false;
};
</script>

<template>
  <div ref="root" class="adt-fm-affix">
    <button
      type="button"
      class="adt-fm-affix-pill"
      :class="{ 'is-set': hasAffix, 'is-open': open }"
      :title="description"
      :aria-label="description"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span v-if="!hasAffix" class="adt-tw-icon-[lucide--plus] adt-tw-size-3" aria-hidden="true"></span>
      <span class="adt-fm-affix-pill-text">{{ label }}</span>
    </button>

    <div v-if="open" ref="pop" class="adt-fm-affix-pop">
      <label class="adt-fm-affix-field">
        <span>{{ __('Prefix', 'woo-product-feed-pro') }}</span>
        <PrefixInput :model-value="prefix" @update:model-value="setPrefix" />
      </label>

      <label class="adt-fm-affix-field">
        <span>{{ __('Suffix', 'woo-product-feed-pro') }}</span>
        <SuffixInput :model-value="suffix" @update:model-value="setSuffix" />
      </label>

      <div class="adt-fm-affix-foot">
        <button type="button" class="adt-fm-affix-clear" :disabled="!hasAffix" @click="clear">
          {{ __('Clear', 'woo-product-feed-pro') }}
        </button>
        <span class="adt-fm-affix-hint">{{ __('Find & replace lives in Rules', 'woo-product-feed-pro') }}</span>
      </div>
    </div>
  </div>
</template>
