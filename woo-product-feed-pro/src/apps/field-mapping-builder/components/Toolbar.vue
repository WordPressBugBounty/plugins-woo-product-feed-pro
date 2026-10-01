<script setup lang="ts">
/***************************************************************************
 * Toolbar Component
 ***************************************************************************
 *
 * Sits above the mapping table: the feed's channel, the required-only filter,
 * a density switch, the preview toggle and auto-map.
 *
 * The channel is a STATIC badge, not a selector. A feed carries exactly one
 * `channel_hash`, so there is no cross-channel requirement matrix to check a
 * mapping against — the badge only says which channel the requirement badges
 * on each row are measured against.
 *
 */
import { computed } from 'vue';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../stores/fieldMappingStore';
import { adtObj } from '@/helpers';

const store = useFieldMappingStore();

const emit = defineEmits<{ (e: 'auto-map'): void }>();

const channelName = computed(() => String(adtObj().channelName ?? ''));

const requirementSource = computed(() =>
  channelName.value
    ? sprintf(
        /* translators: %s: channel name, e.g. Google Shopping */
        __('Requirements shown are those of %s, this feed’s channel.', 'woo-product-feed-pro'),
        channelName.value
      )
    : __('This feed has no channel selected yet.', 'woo-product-feed-pro')
);
</script>

<template>
  <div class="adt-fm-toolbar">
    <div class="adt-fm-toolbar-left">
      <span class="adt-fm-toolbar-label">{{ __('Checking against:', 'woo-product-feed-pro') }}</span>
      <span class="adt-fm-channel-badge" :title="requirementSource">
        <span class="adt-fm-channel-dot" aria-hidden="true"></span>
        {{ channelName || __('No channel', 'woo-product-feed-pro') }}
      </span>
    </div>

    <div class="adt-fm-toolbar-right">
      <!--
        The filter and the density control sit side by side and were read as two
        halves of one setting. Each now says what it actually does.
      -->
      <label
        class="adt-fm-toggle"
        :title="__('Show only the attributes this channel requires, and hide the rest.', 'woo-product-feed-pro')"
      >
        <input v-model="store.onlyRequired" type="checkbox" />
        <span>{{ __('Required only', 'woo-product-feed-pro') }}</span>
      </label>

      <div class="adt-fm-seg" role="group" :aria-label="__('Row density', 'woo-product-feed-pro')">
        <button
          type="button"
          :class="{ active: store.density === 'compact' }"
          :aria-pressed="store.density === 'compact'"
          :title="__('Compact rows: more attributes on screen, no output tag under the name.', 'woo-product-feed-pro')"
          @click="store.density = 'compact'"
        >
          <span class="adt-tw-icon-[lucide--rows-3] adt-tw-size-4" aria-hidden="true"></span>
        </button>
        <button
          type="button"
          :class="{ active: store.density === 'comfy' }"
          :aria-pressed="store.density === 'comfy'"
          :title="__('Comfortable rows: taller rows, with each attribute\'s output tag shown.', 'woo-product-feed-pro')"
          @click="store.density = 'comfy'"
        >
          <span class="adt-tw-icon-[lucide--rows-2] adt-tw-size-4" aria-hidden="true"></span>
        </button>
      </div>

      <button
        type="button"
        class="adt-button adt-button-sm"
        :aria-pressed="store.showPreview"
        :title="
          store.showPreview
            ? __('Hide the output preview pane.', 'woo-product-feed-pro')
            : __('Show what this mapping produces for one product.', 'woo-product-feed-pro')
        "
        @click="store.showPreview = !store.showPreview"
      >
        <span
          class="adt-tw-size-4 adt-tw-mr-1"
          :class="store.showPreview ? 'adt-tw-icon-[lucide--eye]' : 'adt-tw-icon-[lucide--eye-off]'"
          aria-hidden="true"
        ></span>
        {{ __('Preview', 'woo-product-feed-pro') }}
      </button>

      <button
        type="button"
        class="adt-button adt-button-sm"
        :disabled="store.autoMapCandidates === 0"
        :title="
          store.autoMapCandidates === 0
            ? __('Every field the channel suggests a source for is already mapped.', 'woo-product-feed-pro')
            : sprintf(
                /* translators: %d: number of fields auto-map would fill */
                _n(
                  'Fill %d suggested field',
                  'Fill %d suggested fields',
                  store.autoMapCandidates,
                  'woo-product-feed-pro'
                ),
                store.autoMapCandidates
              )
        "
        @click="emit('auto-map')"
      >
        <span class="adt-tw-icon-[lucide--zap] adt-tw-size-4 adt-tw-mr-1" aria-hidden="true"></span>
        {{ __('Auto-map', 'woo-product-feed-pro') }}
      </button>
    </div>
  </div>
</template>
