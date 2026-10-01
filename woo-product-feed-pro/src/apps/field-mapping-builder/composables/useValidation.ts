import { scrollToFirstError } from '@/helpers';
import { useFieldMappingStore, validateCustomFeedName } from '../stores/fieldMappingStore';

// Re-export the custom feed name rules (ported from the legacy vanilla tab, removed in #1042)
// so components can validate inputs directly without going through the store.
export { validateCustomFeedName };

/**
 * Submit-time validation behavior that is not already on the store.
 *
 * Deliberately thin: this app has a single store, so components read validation
 * state (`isFormValid`, `getFieldErrors`, `hasValidationErrors`) and call the
 * validation mutations straight off `useFieldMappingStore()` rather than through
 * a pass-through layer here.
 */
export function useValidation() {
  const store = useFieldMappingStore();

  // Form submission prevention.
  const preventInvalidSubmission = (event: Event) => {
    store.updateValidationErrors();

    if (!store.isFormValid) {
      event.preventDefault();
      event.stopPropagation();

      // Validation errors surface reactively via the store; scroll the first one into view.
      scrollToFirstError();

      return false;
    }

    return true;
  };

  return {
    preventInvalidSubmission,
    scrollToFirstError,
  };
}
