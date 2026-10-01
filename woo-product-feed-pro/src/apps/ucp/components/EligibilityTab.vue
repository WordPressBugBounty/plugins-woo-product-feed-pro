<template>
  <div>
    <!-- ================================ Stat cards ================================ -->
    <div class="adt-stats">
      <div class="adt-card adt-stat">
        <p class="adt-stat__value">
          {{ format(stats?.eligible ?? 0) }}
          <span class="adt-stat__total">/ {{ format(stats?.total ?? 0) }}</span>
        </p>
        <p class="adt-stat__label">{{ __('Products eligible for agentic checkout', 'woo-product-feed-pro') }}</p>
      </div>
      <div class="adt-card adt-stat">
        <p class="adt-stat__value">{{ format(stats?.excluded ?? 0) }}</p>
        <p class="adt-stat__label">{{ __('Excluded by your rules & overrides', 'woo-product-feed-pro') }}</p>
      </div>
      <div class="adt-card adt-stat">
        <p class="adt-stat__value">{{ format(stats?.hard_excluded ?? 0) }}</p>
        <p class="adt-stat__label">{{ __('Hard-excluded (ineligible categories)', 'woo-product-feed-pro') }}</p>
      </div>
    </div>

    <!-- ============================== Eligibility rules ============================== -->
    <div class="adt-card">
      <div class="adt-card__title">
        <span class="adt-icon-tile">⧩</span>
        <div>
          <h3>{{ __('Eligibility rules', 'woo-product-feed-pro') }}</h3>
          <p class="adt-card__subtitle">
            {{ __('Reuses the same rule engine as feed filters & rules', 'woo-product-feed-pro') }}
          </p>
        </div>
      </div>

      <div class="adt-match-banner">
        <span class="adt-match-chip">{{ __('Match all', 'woo-product-feed-pro') }}</span>
        {{ __('of the following conditions to mark a product eligible', 'woo-product-feed-pro') }}
      </div>

      <div v-for="(condition, index) in conditions" :key="index">
        <div v-if="index > 0" class="adt-and-row">
          <span class="adt-and-chip">{{ __('AND', 'woo-product-feed-pro') }}</span>
        </div>
        <div class="adt-condition">
          <select
            v-model="condition.field"
            class="adt-select"
            :aria-label="conditionAriaLabel(index, __('Condition field', 'woo-product-feed-pro'))"
            @change="onFieldChange(condition)"
          >
            <option value="stock_status">{{ __('Stock status', 'woo-product-feed-pro') }}</option>
            <option value="price">{{ __('Product price', 'woo-product-feed-pro') }}</option>
            <option value="category">{{ __('Product category', 'woo-product-feed-pro') }}</option>
          </select>

          <select
            v-model="condition.operator"
            class="adt-select"
            :aria-label="conditionAriaLabel(index, __('Condition operator', 'woo-product-feed-pro'))"
          >
            <option v-for="op in operatorsFor(condition.field)" :key="op.id" :value="op.id">{{ op.label }}</option>
          </select>

          <select
            v-if="condition.field === 'stock_status'"
            v-model="condition.value"
            class="adt-select"
            :aria-label="conditionAriaLabel(index, __('Stock status', 'woo-product-feed-pro'))"
          >
            <option value="instock">{{ __('In stock', 'woo-product-feed-pro') }}</option>
            <option value="outofstock">{{ __('Out of stock', 'woo-product-feed-pro') }}</option>
            <option value="onbackorder">{{ __('On backorder', 'woo-product-feed-pro') }}</option>
          </select>
          <input
            v-else-if="condition.field === 'price'"
            v-model="condition.value"
            type="number"
            step="0.01"
            min="0"
            class="adt-input adt-condition__price"
            :aria-label="conditionAriaLabel(index, __('Product price', 'woo-product-feed-pro'))"
          />
          <VueSelect
            v-else
            v-model="condition.value"
            class="adt-vselect adt-condition__categories"
            :options="categories"
            label="name"
            :reduce="(category: CategoryOption) => category.id"
            multiple
            :placeholder="__('Search categories…', 'woo-product-feed-pro')"
          />

          <button
            type="button"
            class="adt-condition__remove"
            :title="__('Remove condition', 'woo-product-feed-pro')"
            :aria-label="__('Remove condition', 'woo-product-feed-pro')"
            @click="removeCondition(index)"
          >
            <span aria-hidden="true">✕</span>
          </button>
        </div>
      </div>

      <div class="adt-rules-footer">
        <button type="button" class="adt-btn adt-btn--outline" @click="addCondition">
          {{ __('+ Add condition', 'woo-product-feed-pro') }}
        </button>
        <button type="button" class="adt-btn" :disabled="busy" @click="onSaveRules">
          {{ __('Save & re-evaluate', 'woo-product-feed-pro') }}
        </button>
      </div>
    </div>

    <!-- =========================== Overrides + hard-excluded =========================== -->
    <div class="adt-grid-2">
      <div class="adt-card">
        <div class="adt-card__title">
          <span class="adt-icon-tile">☰</span>
          <div>
            <h3>{{ __('Per-product overrides', 'woo-product-feed-pro') }}</h3>
            <p class="adt-card__subtitle">
              {{ __('Search for a product to exclude it — only overridden products are listed', 'woo-product-feed-pro') }}
            </p>
          </div>
        </div>

        <VueSelect
          v-model="pickedProduct"
          class="adt-vselect adt-product-picker"
          :options="productHits"
          label="label"
          :filterable="false"
          :placeholder="__('Search products to exclude…', 'woo-product-feed-pro')"
          @search="onProductSearch"
          @option:selected="addExclusion"
        >
          <template #no-options="{ search: term }">
            {{
              term.length < 2
                ? __('Type at least 2 characters to search your products.', 'woo-product-feed-pro')
                : __('No matching products.', 'woo-product-feed-pro')
            }}
          </template>
        </VueSelect>

        <table class="adt-table">
          <thead>
            <tr>
              <th>{{ __('Product', 'woo-product-feed-pro') }}</th>
              <th>{{ __('Status', 'woo-product-feed-pro') }}</th>
              <th>{{ __('Override', 'woo-product-feed-pro') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td>
                <strong class="adt-product-name">{{ item.name }}</strong>
                <span class="adt-mono adt-product-sku">{{ item.sku || `#${item.id}` }}</span>
              </td>
              <td>
                <span class="adt-pill" :class="statusPill(item).cls">{{ statusPill(item).label }}</span>
              </td>
              <td>
                <span v-if="isHardExcluded(item)" class="adt-pill adt-pill--fail">
                  {{ __('Hard-excluded', 'woo-product-feed-pro') }}
                </span>
                <div v-else class="adt-override">
                  <label class="adt-switch">
                    <input
                      type="checkbox"
                      :checked="item.resolution.eligible"
                      :disabled="busy"
                      :aria-label="toggleOverrideAriaLabel(item)"
                      @change="toggleOverride(item, $event)"
                    />
                    <span class="adt-switch__slider"></span>
                  </label>
                  <button
                    v-if="item.override"
                    type="button"
                    class="adt-override__reset"
                    :title="__('Remove the manual override', 'woo-product-feed-pro')"
                    :aria-label="resetOverrideAriaLabel(item)"
                    @click="clearOverride(item)"
                  >
                    {{ __('auto', 'woo-product-feed-pro') }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!items.length && !busy">
              <td colspan="3" class="adt-empty">
                {{ __('No overrides yet — products follow your rules above. Search above to exclude one.', 'woo-product-feed-pro') }}
              </td>
            </tr>
          </tbody>
        </table>

        <div class="adt-pager">
          <button
            type="button"
            class="adt-btn adt-btn--outline adt-btn--small"
            :disabled="page <= 1 || busy"
            @click="load(page - 1)"
          >
            ‹ {{ __('Prev', 'woo-product-feed-pro') }}
          </button>
          <span>
            {{
              sprintf(
                /* translators: 1: current page number, 2: total number of pages */
                __('Page %1$d of %2$d', 'woo-product-feed-pro'),
                page,
                totalPages,
              )
            }}
          </span>
          <button
            type="button"
            class="adt-btn adt-btn--outline adt-btn--small"
            :disabled="page >= totalPages || busy"
            @click="load(page + 1)"
          >
            {{ __('Next', 'woo-product-feed-pro') }} ›
          </button>
        </div>
      </div>

      <div class="adt-card">
        <div class="adt-card__title">
          <span class="adt-icon-tile"><span class="dashicons dashicons-lock"></span></span>
          <div>
            <h3>{{ __('Hard-excluded categories', 'woo-product-feed-pro') }}</h3>
            <p class="adt-card__subtitle">
              {{ __('Enforced by UCP spec — cannot be overridden', 'woo-product-feed-pro') }}
            </p>
          </div>
        </div>

        <ul class="adt-exclusions">
          <li v-for="(rule, key) in exclusions" :key="key" class="adt-exclusion">
            <span class="adt-exclusion__lock"><span class="dashicons dashicons-lock"></span></span>
            <div>
              <strong class="adt-exclusion__label">{{ rule.label }}</strong>
              <p class="adt-exclusion__desc">{{ rule.description }}</p>
            </div>
          </li>
        </ul>

        <!-- kbUrl arrives over REST; its scheme is validated in the computed. -->
        <a v-if="kbUrl" class="adt-kb-link" :href="kbUrl" target="_blank" rel="noreferrer">
          {{ __("Why can't these products be purchased via UCP?", 'woo-product-feed-pro') }}&nbsp;↗
        </a>
      </div>
    </div>

    <!-- ================================ Footer banner ================================ -->
    <div class="adt-note adt-note--info">
      <!-- Translatable copy with <strong>/<code> placeholders — see eligibilityEnforcementNote. -->
      ⚠&nbsp;
      <!-- prettier-ignore -->
      <span><component :is="part.tag" v-for="(part, index) in eligibilityEnforcementNote" :key="index">{{ part.text }}</component></span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import VueSelect from 'vue-select';
import { __, sprintf } from '@wordpress/i18n';
import { getEligibility, saveRules, searchProducts, setEligibility } from '../api';
import { richTextParts, safeHref } from '../rich-text';
import type { CategoryOption, EligibilityItem, EligibilityStats, HardExclusion, ProductHit } from '../types';

const emit = defineEmits<{
  notify: [message: string];
  stats: [stats: EligibilityStats];
}>();

interface EditableCondition {
  field: 'stock_status' | 'price' | 'category';
  operator: string;
  value: string | number[];
}

const items = ref<EligibilityItem[]>([]);
const exclusions = ref<Record<string, HardExclusion>>({});
const categories = ref<CategoryOption[]>([]);
const conditions = ref<EditableCondition[]>([]);
const stats = ref<EligibilityStats | null>(null);
const page = ref(1);
const totalPages = ref(1);
const busy = ref(false);

// Product picker (search-and-add — the list only shows overridden
// products, so it stays small even on six-figure catalogs).
const productHits = ref<ProductHit[]>([]);
const pickedProduct = ref<ProductHit | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const onProductSearch = (term: string, loading: (state: boolean) => void) => {
  clearTimeout(searchTimer);
  if (term.length < 2) {
    productHits.value = [];
    return;
  }
  loading(true);
  searchTimer = setTimeout(async () => {
    try {
      productHits.value = (await searchProducts(term)).items;
    } catch (e) {
      emit('notify', e instanceof Error ? e.message : __('Product search failed.', 'woo-product-feed-pro'));
    } finally {
      loading(false);
    }
  }, 300);
};

const addExclusion = async (product: ProductHit) => {
  pickedProduct.value = null;
  productHits.value = [];
  busy.value = true;
  try {
    await setEligibility([product.id], 'exclude');
    await reloadItems();
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to exclude the product.', 'woo-product-feed-pro'));
    busy.value = false;
  }
};

const format = (value: number): string => value.toLocaleString();

// The KB URL comes back over REST. Vue happily binds a `javascript:` URI to
// :href, so the scheme is validated before it reaches the anchor rather than
// trusting the server to have escaped it.
const kbUrl = computed(() => safeHref(Object.values(exclusions.value)[0]?.kb_url));

// Mixes translatable copy with a <strong> emphasis and a <code> field path
// (not itself translatable), so both stay sprintf placeholders rather than
// splitting the sentence into multiple __() fragments. The placeholders are
// resolved into rendered elements instead of an HTML string, so a tampered
// translation can only ever produce text (see rich-text.ts).
const eligibilityEnforcementNote = computed(() =>
  richTextParts(
    /* translators: %1$s/%2$s: opening/closing <strong> tag around "twice"; %3$s: <code> snippet showing the feed attribute path (not translatable) */
    __(
      "Eligibility is enforced %1$stwice%2$s: at feed-generation time (products carry %3$s) and again at checkout time with a real-time stock check — so an agent can never complete an order the store can't fulfil.",
      'woo-product-feed-pro',
    ),
    [{ tag: 'strong' }, null, { tag: 'code', text: 'g:native_commerce > checkout_eligibility' }],
  ),
);

// Condition rows are positional, so each control needs its own accessible
// name — a screen reader otherwise announces a row of unnamed comboboxes.
const conditionAriaLabel = (index: number, label: string): string =>
  sprintf(
    /* translators: 1: control name, e.g. "Condition operator"; 2: the condition's position in the list */
    __('%1$s (condition %2$d)', 'woo-product-feed-pro'),
    label,
    index + 1,
  );

const operatorsFor = (field: string): Array<{ id: string; label: string }> => {
  if (field === 'stock_status') {
    return [
      { id: 'is', label: __('is', 'woo-product-feed-pro') },
      { id: 'is_not', label: __('is not', 'woo-product-feed-pro') },
    ];
  }
  if (field === 'price') {
    return [
      { id: 'greater_than', label: __('greater than', 'woo-product-feed-pro') },
      { id: 'less_than', label: __('less than', 'woo-product-feed-pro') },
      { id: 'at_least', label: __('at least', 'woo-product-feed-pro') },
      { id: 'at_most', label: __('at most', 'woo-product-feed-pro') },
    ];
  }
  return [
    { id: 'in', label: __('is in', 'woo-product-feed-pro') },
    { id: 'not_in', label: __('is not in', 'woo-product-feed-pro') },
  ];
};

const onFieldChange = (condition: EditableCondition) => {
  condition.operator = operatorsFor(condition.field)[0].id;
  condition.value = condition.field === 'category' ? [] : condition.field === 'price' ? '10.00' : 'instock';
};

const addCondition = () => {
  conditions.value.push({ field: 'stock_status', operator: 'is', value: 'instock' });
};

const removeCondition = (index: number) => {
  conditions.value.splice(index, 1);
};

const isHardExcluded = (item: EligibilityItem): boolean =>
  !item.resolution.eligible && Object.keys(exclusions.value).includes(item.resolution.reason);

const toggleOverrideAriaLabel = (item: EligibilityItem): string =>
  sprintf(
    /* translators: %s: product name */
    __('Toggle agentic checkout for %s', 'woo-product-feed-pro'),
    item.name,
  );

const resetOverrideAriaLabel = (item: EligibilityItem): string =>
  sprintf(
    /* translators: %s: product name */
    __('Remove the manual override for %s', 'woo-product-feed-pro'),
    item.name,
  );

const statusPill = (item: EligibilityItem): { label: string; cls: string } => {
  if (item.resolution.eligible) {
    return { label: __('Eligible', 'woo-product-feed-pro'), cls: 'adt-pill--pass' };
  }
  if (isHardExcluded(item) || item.resolution.reason === 'not_purchasable') {
    return { label: __('Ineligible', 'woo-product-feed-pro'), cls: 'adt-pill--fail' };
  }
  return { label: __('Excluded', 'woo-product-feed-pro'), cls: 'adt-pill--muted' };
};

const load = async (toPage: number) => {
  busy.value = true;
  try {
    const data = await getEligibility(toPage, '');
    items.value = data.items;
    exclusions.value = data.exclusions;
    categories.value = data.categories;
    stats.value = data.stats;
    conditions.value = data.rules.conditions.map((condition) => ({
      field: condition.field,
      operator: condition.operator,
      value: Array.isArray(condition.value) ? [...condition.value] : String(condition.value),
    }));
    totalPages.value = Math.max(1, data.total_pages);
    // Clamp in case the list shrank (e.g. the last override on the last page
    // was removed) so we never sit on a page past the end.
    page.value = Math.min(toPage, totalPages.value);
    emit('stats', data.stats);
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to load products.', 'woo-product-feed-pro'));
  } finally {
    busy.value = false;
  }
};

const reloadItems = async () => {
  // Refresh items + stats without clobbering unsaved rule edits.
  const edited = conditions.value;
  await load(page.value);
  // If the current page emptied out (last row removed), step back to content.
  if (items.value.length === 0 && page.value > 1) {
    await load(page.value - 1);
  }
  conditions.value = edited;
};

const onSaveRules = async () => {
  busy.value = true;
  try {
    const result = await saveRules({ match: 'all', conditions: conditions.value });
    stats.value = result.stats;
    emit('stats', result.stats);
    await load(page.value);
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to save rules.', 'woo-product-feed-pro'));
    busy.value = false;
  }
};

const toggleOverride = async (item: EligibilityItem, event: Event) => {
  const included = (event.target as HTMLInputElement).checked;
  busy.value = true;
  try {
    await setEligibility([item.id], included ? 'include' : 'exclude');
    await reloadItems();
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to update the product.', 'woo-product-feed-pro'));
    busy.value = false;
  }
};

const clearOverride = async (item: EligibilityItem) => {
  busy.value = true;
  try {
    await setEligibility([item.id], '');
    await reloadItems();
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to update the product.', 'woo-product-feed-pro'));
    busy.value = false;
  }
};

onMounted(() => void load(1));
</script>

<style scoped>
/* Stats */
.adt-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
@media (max-width: 1100px) {
  .adt-stats {
    grid-template-columns: 1fr;
  }
}
.adt-stat__value {
  font-size: 30px;
  font-weight: 800;
  color: var(--adt-navy);
  margin: 0;
}
.adt-stat__total {
  font-size: 18px;
  font-weight: 600;
  color: var(--adt-muted);
}
.adt-stat__label {
  margin: 4px 0 0;
  color: var(--adt-muted);
}

/* Rules */
.adt-match-banner {
  border: 1px dashed #f0b3ca;
  background: #fdf3f7;
  border-radius: 10px;
  padding: 12px 16px;
  margin: 16px 0;
  color: var(--adt-navy);
}
.adt-match-chip {
  background: #fff;
  border: 1px solid #f0b3ca;
  color: var(--adt-pink);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  border-radius: 6px;
  padding: 4px 10px;
  margin-right: 8px;
}
.adt-condition {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--adt-border);
  border-radius: 10px;
  padding: 12px 14px;
}
.adt-condition__price {
  width: 110px;
}
.adt-condition__categories {
  min-width: 220px;
}
.adt-condition__remove {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--adt-muted);
  font-size: 15px;
  cursor: pointer;
}
.adt-condition__remove:hover {
  color: var(--adt-red);
}
.adt-and-row {
  text-align: center;
  margin: 8px 0;
}
.adt-and-chip {
  background: #efeafb;
  color: #6d4fc4;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  border-radius: 6px;
  padding: 3px 12px;
}
.adt-rules-footer {
  display: flex;
  justify-content: space-between;
  margin-top: 16px;
}

/* Overrides table */
.adt-product-picker {
  margin-top: 14px;
  width: 100%;
}
.adt-product-name {
  display: block;
  color: var(--adt-navy);
}
.adt-product-sku {
  color: var(--adt-muted);
  font-weight: 500;
  font-size: 12px;
}
.adt-override {
  display: flex;
  align-items: center;
  gap: 10px;
}
.adt-override__reset {
  background: none;
  border: none;
  color: var(--adt-muted);
  font-size: 12px;
  cursor: pointer;
  text-decoration: underline;
}
.adt-override__reset:hover {
  color: var(--adt-pink);
}
.adt-pager {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-top: 14px;
  color: var(--adt-muted);
}
.adt-empty {
  color: var(--adt-muted);
}

/* Hard exclusions */
.adt-exclusions {
  list-style: none;
  margin: 14px 0 0;
  padding: 0;
}
.adt-exclusion {
  display: flex;
  gap: 12px;
  padding: 10px 0;
}
.adt-exclusion__lock {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  background: var(--adt-pink-soft);
  color: var(--adt-pink);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
}
.adt-exclusion__lock .dashicons {
  font-size: 16px;
  width: 16px;
  height: 16px;
}
.adt-exclusion__label {
  color: var(--adt-navy);
}
.adt-exclusion__desc {
  margin: 2px 0 0;
  color: var(--adt-muted);
}
.adt-kb-link {
  display: inline-block;
  margin-top: 10px;
  color: var(--adt-pink);
  font-weight: 700;
  text-decoration: none;
}
.adt-kb-link:hover {
  text-decoration: underline;
}
</style>
