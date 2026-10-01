<script setup lang="ts">
/***************************************************************************
 * App Component
 ***************************************************************************
 *
 * Root component of the field mapping builder. Loads the store on mount,
 * pre-populates the required channel rows for feeds with no mapping yet,
 * mirrors the `feed_attributes` state into the hidden JSON field the classic
 * form POST submits, gates submission on validation, and warns before
 * navigating away with unsaved edits.
 *
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useFieldMappingStore } from './stores/fieldMappingStore';
import { useValidation } from './composables/useValidation';
import FieldMappingTable from './components/FieldMappingTable.vue';
import GroupNav from './components/GroupNav.vue';
import Toolbar from './components/Toolbar.vue';
import PreviewPane from './components/PreviewPane.vue';
import MigrationNotice from './components/common/MigrationNotice.vue';
import { adtObj } from '@/helpers';

const store = useFieldMappingStore();
const { loading, error, feedAttributes, migrationRan, hasValidationErrors } = storeToRefs(store);
const validation = useValidation();

// Dirty tracking starts only after the initial payload (and any
// pre-population) has hydrated the store, so loading never counts as an edit.
const hydrated = ref(false);
const isDirty = ref(false);
let isSubmitting = false;

const showMigrationNotice = ref(false);

// A feed being created has no `id` yet, and its save advances to the next step.
const isNewFeed = computed(() => !new URLSearchParams(window.location.search).get('id'));

const saveLabel = computed(() =>
  isNewFeed.value
    ? __('Save & Continue', 'woo-product-feed-pro')
    : __('Save Changes', 'woo-product-feed-pro')
);

// Blocked only by validation. An incomplete mapping is still savable.
const saveDisabled = computed(() => !hydrated.value || hasValidationErrors.value);

/*
 * Legacy parity: while a feed is being created, saving advances to the next
 * step, and the footer says which one. The template resolves the label from the
 * feed's own tab list - it is empty when editing, which is what hides the hint.
 */
const nextStep =
  (document.getElementById('field-mapping-builder-app') as HTMLElement | null)?.dataset.nextStep ?? '';

const nextStepHint = nextStep
  ? sprintf(
      /* translators: %s: name of the next configuration step, e.g. Category Mapping */
      __('Saving takes you to the %s step.', 'woo-product-feed-pro'),
      nextStep
    )
  : '';

/**
 * What the sticky save bar says.
 *
 * A disabled Save with no explanation is the worst version of this bar, so it
 * reports the blocking reason first, then unsaved changes, then a resting state.
 */
const saveStatus = computed(() => {
  if (!hydrated.value) {
    return { text: __('Loading the mapping…', 'woo-product-feed-pro'), tone: 'muted' };
  }
  if (hasValidationErrors.value) {
    return { text: __('Fix the highlighted fields before saving.', 'woo-product-feed-pro'), tone: 'error' };
  }
  const missing = store.overallSummary.missing;
  if (missing > 0) {
    return {
      text: sprintf(
        /* translators: %d: number of required fields with no mapping */
        _n(
          '%d required field still unmapped — you can save and finish later.',
          '%d required fields still unmapped — you can save and finish later.',
          missing,
          'woo-product-feed-pro'
        ),
        missing
      ),
      tone: 'warn',
    };
  }
  if (isDirty.value) {
    return { text: __('You have unsaved changes.', 'woo-product-feed-pro'), tone: 'muted' };
  }
  return { text: '', tone: 'muted' };
});

/**
 * Mirror the store into the hidden field the classic form POST submits.
 *
 * DATA-LOSS GUARD: nothing is written before the store hydrates. The
 * template ships the hidden field empty, and the save handler's strict
 * decode treats an empty payload as "leave the saved mapping alone" — so a
 * submit that somehow fires while loading (or after a failed load) can
 * never replace a working mapping with `[]`.
 */
const updateHiddenField = () => {
  if (!hydrated.value) {
    return;
  }

  const hiddenField = document.getElementById('field-mapping-data-field') as HTMLInputElement | null;
  if (hiddenField) {
    hiddenField.value = JSON.stringify(feedAttributes.value);
  }
};

/**
 * Gate the classic form POST on validation; a passing submit clears the
 * dirty state so the beforeunload warning stays quiet.
 *
 * Submits are also blocked outright until the store has hydrated — before
 * that the hidden field is empty and there is nothing meaningful to save.
 */
const onFormSubmit = (event: Event) => {
  if (!hydrated.value) {
    event.preventDefault();
    event.stopPropagation();
    return;
  }

  if (validation.preventInvalidSubmission(event)) {
    isSubmitting = true;
  }
};

/**
 * Warn before navigating away with unsaved edits (legacy tab parity).
 */
const onBeforeUnload = (event: BeforeUnloadEvent) => {
  if (isDirty.value && !isSubmitting) {
    event.preventDefault();
    // Legacy fallback: modern browsers ignore the text and show their own.
    event.returnValue = __(
      'Leaving this page will result in losing your feed configuration. Are you sure you want to leave?',
      'woo-product-feed-pro'
    );
  }
};

// The preview endpoint persists nothing, so it is safe to call on every edit -
// but not on every keystroke. One trailing call per quiet moment is enough.
const PREVIEW_DEBOUNCE_MS = 400;
let previewTimer: ReturnType<typeof setTimeout> | undefined;

const schedulePreview = () => {
  if (!store.showPreview) return;
  clearTimeout(previewTimer);
  previewTimer = setTimeout(() => store.loadPreview(), PREVIEW_DEBOUNCE_MS);
};

watch(
  feedAttributes,
  () => {
    updateHiddenField();
    if (hydrated.value) {
      isDirty.value = true;
      schedulePreview();
    }
  },
  { deep: true, immediate: true }
);

// Turning the pane back on should show current data, not whatever was last fetched.
watch(
  () => store.showPreview,
  (visible) => {
    if (visible && hydrated.value) schedulePreview();
  }
);

/**
 * Fill every unmapped row the catalog suggests a source for.
 *
 * Confirmed first: auto-map writes to many rows at once, and an accidental
 * click on a nearly-finished mapping is expensive to undo by hand. The button
 * is disabled when there is nothing to fill, so this only ever asks about real
 * work.
 */
const onAutoMap = () => {
  const candidates = store.autoMapCandidates;
  if (candidates === 0) {
    return;
  }

  const message = sprintf(
    /* translators: %d: number of fields that will be filled */
    _n(
      'Auto-map will fill %d field that has no source yet, using the suggestions for this channel. Fields you have already mapped are left alone. Continue?',
      'Auto-map will fill %d fields that have no source yet, using the suggestions for this channel. Fields you have already mapped are left alone. Continue?',
      candidates,
      'woo-product-feed-pro'
    ),
    candidates
  );

  // eslint-disable-next-line no-alert -- a bulk write needs an explicit confirmation.
  if (!window.confirm(message)) {
    return;
  }

  store.autoMapSuggested(String(adtObj().storeCurrency ?? ''));
};

/**
 * Append an empty custom field row and reveal it.
 *
 * Clears the filters first: a search or a group selection would leave the new
 * row filtered out of the table entirely. `addAttribute` flags the row so it
 * scrolls into view and takes the caret once rendered.
 */
const onAddCustomField = () => {
  store.activeGroup = 'all';
  store.search = '';
  store.onlyRequired = false;
  store.addAttribute({ is_custom: true });
};

const form = () => document.getElementById('field_mapping') as HTMLFormElement | null;


onMounted(async () => {
  // Block saving until the payload is in: gate the submit event and disable
  // the button before anything async happens.
  form()?.addEventListener('submit', onFormSubmit);
  window.addEventListener('beforeunload', onBeforeUnload);


  // Get feed ID from URL parameters ('new' sentinel for unsaved feeds).
  const urlParams = new URLSearchParams(window.location.search);
  const feedId = urlParams.get('id') || 'new';

  // Load existing field mappings.
  await store.loadFieldMappings(feedId);

  // A failed load keeps the hidden field empty and the save button disabled,
  // so a working mapping can never be overwritten with an empty one.
  if (store.error) {
    return;
  }

  // Legacy parity: a feed with no mapping yet starts from the channel's
  // required rows, with `{{CURRENCY}}` placeholders resolved store-side.
  store.prepopulateRequiredRows(String(adtObj().storeCurrency ?? ''));

  showMigrationNotice.value = migrationRan.value;

  // Page/post rows store an ID; fetch the handful of titles they need.
  store.resolveObjectTitles();

  // Everything the load wrote has flushed; edits from here on are dirty.
  await nextTick();
  hydrated.value = true;
  updateHiddenField();


  if (store.showPreview) {
    // Re-use the product this feed was last previewed against, if any.
    store.restorePreviewProduct();
    store.loadPreview();
  }
});

onBeforeUnmount(() => {
  form()?.removeEventListener('submit', onFormSubmit);
  window.removeEventListener('beforeunload', onBeforeUnload);
  clearTimeout(previewTimer);
});
</script>

<template>
  <div class="adt-field-mapping-builder">
    <!-- Live region so screen readers are notified when loading starts and resolves. -->
    <div role="status" aria-live="polite">
      <p v-if="loading">{{ __('Loading field mappings…', 'woo-product-feed-pro') }}</p>
    </div>

    <div v-if="loading" class="adt-field-mapping-skeleton" aria-hidden="true">
      <div v-for="row in 6" :key="row" class="adt-tw-h-9 adt-tw-bg-gray-100 adt-tw-rounded-md adt-tw-animate-pulse adt-tw-mb-2"></div>
    </div>

    <template v-else>
      <div
        v-if="error"
        class="adt-validation-error adt-tw-bg-red-50 adt-tw-border adt-tw-border-red-200 adt-tw-text-red-700 adt-tw-text-sm adt-tw-rounded-md adt-tw-p-3 adt-tw-mb-3"
      >
        {{ error }}
      </div>

      <div v-if="showMigrationNotice" class="adt-tw-mb-3">
        <MigrationNotice :close-migration-notice="() => (showMigrationNotice = false)" />
      </div>

      <div v-if="!error" class="adt-fm-shell" :class="{ 'adt-fm-shell--with-preview': store.showPreview }">
        <div class="adt-fm-side">
          <GroupNav />
          <div class="adt-fm-save-bar">
            <div class="adt-fm-save-status">
              <span v-if="saveStatus.text" :class="`adt-fm-save-status-text is-${saveStatus.tone}`">
                {{ saveStatus.text }}
              </span>
            </div>
            <button
              id="savebutton"
              type="submit"
              class="adt-button adt-button-sm adt-button-primary"
              :disabled="saveDisabled"
            >
              {{ saveLabel }}
            </button>
            <p v-if="nextStepHint" class="adt-fm-next-step">{{ nextStepHint }}</p>
          </div>
        </div>

        <section class="adt-fm-main">
          <Toolbar @auto-map="onAutoMap" />
          <FieldMappingTable @add-custom-field="onAddCustomField" />
        </section>

        <PreviewPane v-if="store.showPreview" />
      </div>

    </template>
  </div>
</template>
