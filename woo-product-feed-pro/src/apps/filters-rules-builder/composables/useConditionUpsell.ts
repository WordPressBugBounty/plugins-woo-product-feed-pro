import { ref } from 'vue';
import { isEliteActive, showEliteUpsellModal } from '@/helpers';

/**
 * Elite-only condition operators mapped to their upsell modal key.
 *
 * Selecting one of these on a non-Elite site opens the upgrade modal instead of
 * applying the condition. Shared by the rule and filter builder items.
 */
const ELITE_ONLY_CONDITIONS: Record<string, string> = {
  character_count_greater_than: 'rule_condition_character_count',
  character_count_greater_than_or_equal: 'rule_condition_character_count',
  character_count_less_than: 'rule_condition_character_count',
  character_count_less_than_or_equal: 'rule_condition_character_count',
};

/**
 * Gate Elite-only conditions behind the upgrade modal in the filters/rules builder.
 *
 * Provides a per-item force-update key (to revert the condition <select> back to the
 * stored value) and a guard used by the condition change handlers.
 */
export function useConditionUpsell() {
  // Bumped to force the condition <select> to re-render back to the stored value.
  const conditionForceUpdateKey = ref(0);

  /**
   * Whether the given condition is Elite-only and blocked on this (non-Elite) site.
   *
   * When it is, the upgrade modal is opened and the dropdown is flagged to revert;
   * callers should skip persisting the condition and return early.
   */
  const isConditionGated = (condition: string): boolean => {
    if (!isEliteActive() && ELITE_ONLY_CONDITIONS[condition]) {
      showEliteUpsellModal(ELITE_ONLY_CONDITIONS[condition]);
      conditionForceUpdateKey.value += 1;
      return true;
    }
    return false;
  };

  return { conditionForceUpdateKey, isConditionGated };
}
