/**
 * Clipboard helper for the UCP admin app.
 *
 * Mirrors the strategy used by the Manage Feeds copy-URL button
 * (src/vanilla/manage-feeds/index.ts): the async Clipboard API is only
 * available in secure contexts, so fall back to a temporary input +
 * `document.execCommand('copy')` everywhere else.
 *
 * @package AdTribes\PFP
 */

/**
 * Legacy copy method using execCommand (non-secure contexts, older browsers).
 *
 * @param {string} text Text to copy.
 *
 * @return {boolean} True when the text was copied.
 */
const legacyCopyToClipboard = (text: string): boolean => {
  try {
    const tempInput = document.createElement('input');
    tempInput.value = text;
    document.body.appendChild(tempInput);

    tempInput.select();
    tempInput.setSelectionRange(0, 99999); // For mobile devices.

    // Deprecated, but the only option outside a secure context.
    const success = document.execCommand('copy');

    document.body.removeChild(tempInput);

    return success;
  } catch {
    return false;
  }
};

/**
 * Copy text to the clipboard.
 *
 * @param {string} text Text to copy.
 *
 * @return {Promise<boolean>} Resolves true when the text was copied.
 */
export const copyToClipboard = async (text: string): Promise<boolean> => {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Clipboard permission denied — try the legacy path before giving up.
      return legacyCopyToClipboard(text);
    }
  }

  return legacyCopyToClipboard(text);
};
