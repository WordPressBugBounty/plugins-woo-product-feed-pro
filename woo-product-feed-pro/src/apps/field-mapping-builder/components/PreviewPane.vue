<script setup lang="ts">
/***************************************************************************
 * PreviewPane Component
 ***************************************************************************
 *
 * Right column: the in-progress mapping resolved against one sample product.
 *
 * SCOPE. The XML comes from the real `Feed_Writer_XML`, so nested tags, entity
 * escaping and duplicate-tag handling are the generator's own. The product data
 * feeding it is resolved by a preview-only builder, because the generation
 * path's builder is inseparable from the code that rewrites the live feed file.
 * Filters, Rules and tax transformations therefore do NOT run
 * here. The pane says so, so nobody reads it as final feed output.
 *
 */
import { computed, h, ref, watch } from 'vue';
import VueSelect from 'vue-select';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from '../stores/fieldMappingStore';
import type { PreviewProductOption } from '../types';

const store = useFieldMappingStore();

const OpenIndicator = { render: () => h('span', { class: 'adt-tw-icon-[lucide--chevron-down]' }) };

// The picker stays closed until asked for, so the pane leads with the product
// rather than with a control.
const picking = ref(false);

// The card above the picker already states the product and its ID, and the
// sidebar is narrow, so the closed control shows the name alone. The ID is on
// every dropdown option's second line, where it does the disambiguating.
const productLabel = (product: PreviewProductOption) => product.name;

const productMeta = (product: PreviewProductOption) =>
  product.sku ? `#${product.id} · ${product.sku}` : `#${product.id}`;

const selectedProduct = computed<PreviewProductOption | null>(() => {
  const current = store.previewProduct;
  return current ? { id: current.id, name: current.name, sku: current.sku } : null;
});

// vue-select's search is synchronous over its options, so fetch matches as the
// user types rather than shipping every product to the browser.
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const onSearch = (term: string, loading: (state: boolean) => void) => {
  clearTimeout(searchTimer);
  loading(true);
  searchTimer = setTimeout(async () => {
    await store.searchProducts(term);
    loading(false);
  }, 300);
};

const onPick = async (option: PreviewProductOption | null) => {
  if (!option) return;

  store.setPreviewProduct(option.id);
  picking.value = false;

  // Offer the variation step straight away for a variable product, rather than
  // waiting for the preview to come back and report a parent.
  await store.loadVariations(option.has_variations ? option.id : 0);
  store.loadPreview();
};

/**
 * The parent whose variations are on offer, or 0 when there are none.
 */
const variationParentId = computed(() => store.previewProduct?.parent || 0);

/**
 * Variation choices, with the parent product itself as the first entry so the
 * user can always get back to it.
 */
const variationChoices = computed(() => {
  if (store.variationOptions.length === 0) return [];

  const parentId = variationParentId.value || store.variationOptions[0]?.parent || 0;

  return [
    { id: parentId, name: __('Parent product', 'woo-product-feed-pro'), sku: '' },
    ...store.variationOptions,
  ];
});

const selectedVariation = computed(() => {
  const current = store.previewProduct;
  if (!current) return null;
  return variationChoices.value.find((option) => option.id === current.id) ?? null;
});

const onPickVariation = (option: PreviewProductOption | null) => {
  if (!option) return;
  store.setPreviewProduct(option.id);
  store.loadPreview();
};

const openPicker = async () => {
  picking.value = true;
  if (store.productOptions.length === 0) {
    await store.searchProducts('');
  }
};

// Reopening the pane should not strand a half-finished search.
watch(
  () => store.showPreview,
  (visible) => {
    if (!visible) picking.value = false;
  }
);

// Pretty-print the single-line <item> the endpoint returns, so the pane can
// show one element per line the way the design does.
const xmlLines = computed(() => {
  if (!store.previewXml) return [];

  return store.previewXml
    .replace(/></g, '>\n<')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
});

const indentFor = (line: string): number => {
  if (line.startsWith('</item') || line.startsWith('<item')) return 0;
  return 1;
};
</script>

<template>
  <aside class="adt-fm-preview">
    <div class="adt-fm-preview-head">
      <div>
        <div class="adt-fm-preview-title">{{ __('Output preview', 'woo-product-feed-pro') }}</div>
        <div class="adt-fm-preview-sub">{{ __('Your mapping, resolved against one product', 'woo-product-feed-pro') }}</div>
      </div>
      <button
        type="button"
        class="adt-fm-icon-btn"
        :aria-label="__('Hide preview', 'woo-product-feed-pro')"
        @click="store.showPreview = false"
      >
        <span class="adt-tw-icon-[lucide--chevron-right] adt-tw-size-4" aria-hidden="true"></span>
      </button>
    </div>

    <div v-if="store.previewProduct" class="adt-fm-preview-product">
      <div
        v-if="store.previewProduct.image"
        class="adt-fm-preview-thumb"
        :style="{ backgroundImage: `url(${store.previewProduct.image})` }"
        role="img"
        :aria-label="store.previewProduct.name"
      ></div>
      <div class="adt-fm-preview-product-info">
        <div class="adt-fm-preview-product-name">{{ store.previewProduct.name }}</div>
        <div v-if="store.previewProduct.variation" class="adt-fm-preview-variation">
          {{ store.previewProduct.variation }}
        </div>
        <div class="adt-fm-preview-product-meta">
          {{ store.previewProduct.variation ? __('Variation', 'woo-product-feed-pro') : __('Product', 'woo-product-feed-pro') }}
          #{{ store.previewProduct.id }}
          <template v-if="store.previewProduct.sku"> · {{ store.previewProduct.sku }}</template>
        </div>
        <button v-if="!picking" type="button" class="adt-fm-preview-change" @click="openPicker">
          {{ __('Preview a different product', 'woo-product-feed-pro') }}
        </button>
      </div>
    </div>

    <div v-if="picking" class="adt-fm-preview-picker">
      <label class="adt-fm-preview-picker-label" for="adt-fm-product-picker">
        {{ __('Preview against', 'woo-product-feed-pro') }}
      </label>
      <div class="adt-source-value-select-container">
        <vue-select
          input-id="adt-fm-product-picker"
          :model-value="selectedProduct"
          :options="store.productOptions"
          :get-option-label="productLabel"
          :get-option-key="(option: PreviewProductOption) => option.id"
          :filterable="false"
          :loading="store.productSearchLoading"
          :components="{ OpenIndicator }"
          :clearable="false"
          :placeholder="__('Search products by name or ID…', 'woo-product-feed-pro')"
          @search="onSearch"
          @update:model-value="onPick"
        >
          <template #option="option">
            <div class="adt-fm-product-option">
              <div class="adt-fm-product-option-name">{{ option.name }}</div>
              <div class="adt-fm-product-option-meta">{{ productMeta(option) }}</div>
            </div>
          </template>
          <template #no-options>
            <div class="adt-fm-preview-picker-empty">{{ __('No products match.', 'woo-product-feed-pro') }}</div>
          </template>
        </vue-select>
      </div>
      <button type="button" class="adt-fm-preview-change" @click="picking = false">
        {{ __('Cancel', 'woo-product-feed-pro') }}
      </button>
    </div>

    <div v-if="variationChoices.length > 0" class="adt-fm-preview-variations">
      <label class="adt-fm-preview-picker-label" for="adt-fm-variation-picker">
        {{ __('Variation', 'woo-product-feed-pro') }}
      </label>
      <div class="adt-source-value-select-container">
        <vue-select
          input-id="adt-fm-variation-picker"
          :model-value="selectedVariation"
          :options="variationChoices"
          :get-option-label="(option: PreviewProductOption) => option.name"
          :get-option-key="(option: PreviewProductOption) => option.id"
          :components="{ OpenIndicator }"
          :clearable="false"
          @update:model-value="onPickVariation"
        >
          <template #option="{ name }">
            <div class="adt-fm-plain-option" :title="name">{{ name }}</div>
          </template>
          <template #selected-option="{ name }">
            <span class="adt-fm-selected-label" :title="name">{{ name }}</span>
          </template>
        </vue-select>
      </div>

      <!-- The route caps the list, so say so rather than imply it is complete. -->
      <p v-if="store.variationsTruncated" class="adt-fm-preview-variations-note">
        {{ __('Showing the first 100 variations of this product.', 'woo-product-feed-pro') }}
      </p>
    </div>

    <div role="status" aria-live="polite" class="adt-fm-preview-status">
      <span v-if="store.previewLoading">{{ __('Resolving preview…', 'woo-product-feed-pro') }}</span>
    </div>

    <div v-if="store.previewError" class="adt-fm-preview-error">{{ store.previewError }}</div>

    <!--
      Not an error: this feed's channel generates through the legacy writer, so
      there is nothing this pane could show that generation would produce. Say
      so plainly instead of rendering Google-shaped output the feed cannot emit.
    -->
    <div v-else-if="store.previewUnsupported" class="adt-fm-preview-unsupported">
      <span class="adt-tw-icon-[lucide--info] adt-tw-size-4" aria-hidden="true"></span>
      <span>{{ store.previewUnsupported }}</span>
    </div>

    <div v-else class="adt-fm-preview-xml">
      <div v-for="(line, index) in xmlLines" :key="index" class="adt-fm-xml-line" :data-indent="indentFor(line)">
        {{ line }}
      </div>
      <p v-if="!xmlLines.length && !store.previewLoading" class="adt-fm-preview-empty">
        {{ __('Map a field to see the output.', 'woo-product-feed-pro') }}
      </p>
    </div>

    <div class="adt-fm-preview-foot">
      <p v-if="store.notPreviewableCount > 0" class="adt-fm-preview-foot-note">
        {{
          sprintf(
            /* translators: %d: number of attributes that cannot be previewed */
            _n(
              '%d attribute resolves during generation and is not shown above.',
              '%d attributes resolve during generation and are not shown above.',
              store.notPreviewableCount,
              'woo-product-feed-pro'
            ),
            store.notPreviewableCount
          )
        }}
      </p>
      <p>
        {{
          __(
            'Mapping preview only. Filters and Rules are applied when the feed is generated, not here.',
            'woo-product-feed-pro'
          )
        }}
      </p>
    </div>
  </aside>
</template>
