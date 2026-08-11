/**
 * Validation helper functions for Filters and Rules components
 * Provides common validation utilities that can be reused across components
 */

import { __ } from '@wordpress/i18n';

/**
 * Decimal numeric shape accepted by PHP's `is_numeric()`: an optional sign, digits with
 * an optional fraction (or a bare fraction), and an optional exponent.
 *
 * `Number()` alone is too permissive to mirror the PHP side — it also coerces hex, octal
 * and binary literals (`Number('0x10')` is `16`), which `is_numeric()` rejects. Accepting
 * those here would save a range the Elite handler then parses to nothing.
 */
const NUMERIC_BOUND = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;

/**
 * Validate the value of a `between` condition: two numeric bounds separated by a comma.
 *
 * Returns an error message, or null when the range is usable. Mirrors the parsing in
 * Elite's Between_Condition handler, which matches nothing when the range is malformed
 * — without this check a typo like `100` would silently produce an empty feed.
 */
export const getBetweenValueError = (value: unknown): string | null => {
  const bounds = String(value ?? '')
    .split(',')
    .map((bound) => bound.trim());

  if (bounds.length !== 2 || bounds.some((bound) => !NUMERIC_BOUND.test(bound))) {
    return __('Enter two numbers separated by a comma, e.g. 100,200', 'woo-product-feed-pro');
  }

  return null;
};

/**
 * Generate CSS classes for form fields based on validation state
 */
export const getValidationClasses = (
  baseClasses: string,
  hasErrors: boolean,
  errorClasses: string = 'adt-tw-border-red-500 adt-tw-focus-border-red-500 adt-tw-focus-ring-red-500'
): string => {
  return hasErrors ? `${baseClasses} ${errorClasses}` : baseClasses;
};

/**
 * Generate CSS classes for container elements based on validation state
 */
export const getContainerValidationClasses = (
  hasErrors: boolean,
  errorClasses: string = 'adt-tw-border-red-300 adt-tw-shadow-red-100',
  normalClasses: string = 'adt-tw-border-gray-200 adt-tw-shadow-sm'
): string => {
  return hasErrors ? errorClasses : normalClasses;
};

/**
 * Check if a field has validation errors
 */
export const hasValidationErrors = (errors: string[]): boolean => {
  return errors.length > 0;
};

/**
 * Scroll to the first validation error element
 */
export const scrollToFirstError = (selector: string = '.adt-field-error, .adt-validation-error'): void => {
  const firstErrorElement = document.querySelector(selector);
  if (firstErrorElement) {
    firstErrorElement.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    });
  }
};
