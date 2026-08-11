/**
 * Placeholder helpers for Filters and Rules components
 */

import { __ } from '@wordpress/i18n';

/**
 * The parts of a filter/rule field's data that decide its value placeholder.
 *
 * A logic row's data is a plain string (`and` / `or`) rather than an object, so the
 * union mirrors `FilterField['data']` / `RuleField['data']` without importing either store.
 */
type ValueFieldData = { attribute?: string; condition?: string } | string | null | undefined;

/**
 * Placeholder for a filter/rule row's value input.
 *
 * Shared by `FilterItem` and `RuleItem`, which render the same input. The `between`
 * condition takes two bounds in the one field, so it needs a format hint — "Enter value"
 * would leave the min,max shape undiscoverable. Category attributes pick from a dropdown
 * instead of typing.
 *
 * Every other condition returns an empty string so `ValueInput` applies its own translated
 * "Enter value" fallback, keeping that string defined in exactly one place.
 */
export const getValuePlaceholder = (data: ValueFieldData): string => {
  if (typeof data !== 'object' || data === null) {
    return '';
  }

  if (data.attribute === 'categories' || data.attribute === 'raw_categories') {
    return __('Select category', 'woo-product-feed-pro');
  }

  if (data.condition === 'between') {
    return __('Min,Max (e.g., 100,200)', 'woo-product-feed-pro');
  }

  return '';
};
