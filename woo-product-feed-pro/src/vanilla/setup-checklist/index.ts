declare var jQuery: any;

import './style.scss';

/**
 * Setup checklist ("Getting Started") onboarding card behaviour.
 *
 * Handles: copy feed URL + confirm submit, one-click sister-plugin install
 * (reusing the existing `adt_install_activate_plugin` AJAX), dismiss/restore,
 * and the in-place progress / celebration transitions.
 */
(function (w: any, d: Document, $: any) {
  const { __, sprintf } = w.wp.i18n;
  const cfg = w.adtSetupChecklist || {};
  const ajaxurl: string = w.ajaxurl;

  const post = (data: Record<string, any>) =>
    $.ajax({ url: ajaxurl, type: 'POST', data });

  $(function () {
    const $card = $('.adt-setup-checklist');
    const cardNonce: string = $card.data('nonce') || cfg.nonce;

    // Sister-plugin icons come from the wp.org CDN and can 404 or be blocked
    // by a strict script-src CSP; bound here instead of an inline `onerror`
    // attribute so the fallback still runs under such a policy.
    $card.find('.adt-setup-checklist__sister-icon').on('error', function (this: HTMLImageElement) {
      // Built with the attribute-object form rather than an HTML string so the
      // plugin name in `alt` is set via setAttribute and never parsed as HTML.
      $(this).replaceWith(
        $('<span>', {
          class:
            'adt-setup-checklist__sister-icon adt-setup-checklist__sister-icon--fallback adt-tw-icon-[lucide--puzzle]',
          role: 'img',
          'aria-label': this.alt,
        })
      );
    });

    /**
     * Copy text to the clipboard with a legacy fallback.
     */
    function copyToClipboard(text: string): Promise<void> {
      function legacyCopyToClipboard(): Promise<void> {
        return new Promise((resolve, reject) => {
          try {
            const input = d.createElement('input');
            input.value = text;
            d.body.appendChild(input);
            input.select();
            const ok = d.execCommand('copy');
            d.body.removeChild(input);
            if (ok) {
              resolve();
            } else {
              reject(new Error('execCommand copy failed'));
            }
          } catch (e) {
            reject(e);
          }
        });
      }

      if (navigator.clipboard && w.isSecureContext) {
        // A denied clipboard permission rejects here too; fall back to the
        // legacy path instead of failing the essential step outright.
        return navigator.clipboard.writeText(text).catch(legacyCopyToClipboard);
      }
      return legacyCopyToClipboard();
    }

    /**
     * Mark a step row as done (green check, no actions).
     */
    function markStepDone($step: any) {
      const $check = $step.find('.adt-setup-checklist__check');
      $check
        .removeClass('adt-setup-checklist__check--todo')
        .addClass('adt-setup-checklist__check--done')
        .html('<span class="adt-tw-icon-[lucide--check]"></span>');
    }

    /**
     * Recompute the essential-step progress and update the header / bar.
     */
    function refreshProgress() {
      const $steps = $card.find('.adt-setup-checklist__step');
      let total = 0;
      let done = 0;
      $steps.each(function (this: HTMLElement) {
        const $step = $(this);
        // Essential steps are the ones the server did not flag as optional.
        if ($step.data('optional') === 1) {
          return;
        }
        total += 1;
        if ($step.find('.adt-setup-checklist__check--done').length) {
          done += 1;
        }
      });

      const pct = total > 0 ? Math.round((done / total) * 100) : 0;
      $card.find('.adt-setup-checklist__progress-track').attr('aria-valuenow', String(pct));
      $card.find('.adt-setup-checklist__progress-fill').css('width', pct + '%');
      $card
        .find('.adt-setup-checklist__progress-label')
        /* translators: 1: completed essential steps, 2: total essential steps. */
        .text(sprintf(__('%1$d / %2$d done', 'woo-product-feed-pro'), done, total));
      $card
        .find('.adt-setup-checklist__subline')
        .text(
          total - done === 1
            ? /* translators: 1: completed essential steps, 2: total essential steps. */
              sprintf(__('%1$d of %2$d essential steps done — almost there!', 'woo-product-feed-pro'), done, total)
            : /* translators: 1: completed essential steps, 2: total essential steps. */
              sprintf(__('%1$d of %2$d essential steps done', 'woo-product-feed-pro'), done, total)
        );

      // Swap the header mascot to match the new progress stage, without a reload:
      // idea (getting started) -> feeds (halfway) -> celebrate (all done).
      const mascots = cfg.mascots || {};
      let mascotUrl = mascots.idea;
      if (total > 0 && done === total) {
        mascotUrl = mascots.celebrate || mascotUrl;
      } else if (pct >= 50) {
        mascotUrl = mascots.feeds || mascotUrl;
      }
      if (mascotUrl) {
        $card.find('.adt-setup-checklist__mascot').attr('src', mascotUrl);
      }

      if (total > 0 && done === total) {
        celebrate();
      }
    }

    /**
     * Switch the card into the "all done" celebration state.
     */
    function celebrate() {
      $card.find('.adt-setup-checklist__headline').text(__("You're all set up!", 'woo-product-feed-pro'));
      $card
        .find('.adt-setup-checklist__subline')
        .text(__('Your feeds are ready to reach shoppers.', 'woo-product-feed-pro'));
      const $footer = $card.find('.adt-setup-checklist__footer');
      $footer.find('.adt-setup-checklist__help').remove();
      if (!$footer.find('.adt-setup-checklist__celebrate').length) {
        $footer.prepend(
          '<span class="adt-setup-checklist__celebrate"><span class="adt-tw-icon-[lucide--party-popper]"></span>' +
            __('All essentials complete — great work!', 'woo-product-feed-pro') +
            '</span>'
        );
      }
    }

    // --- Copy Feed URL ------------------------------------------------------
    $card.on('click', '.adt-setup-checklist__copy', function (this: HTMLElement) {
      const $wrap = $(this).closest('.adt-setup-checklist__submit');
      const url = $wrap.data('feed-url');
      if (!url) {
        return;
      }
      copyToClipboard(String(url)).then(
        () => {
          $(this).attr('hidden', 'hidden');
          $wrap.find('.adt-setup-checklist__copied').removeAttr('hidden');
          $wrap.find('.adt-setup-checklist__mark').removeAttr('hidden').trigger('focus');
        },
        () => w.alert(__('Could not copy the feed URL.', 'woo-product-feed-pro'))
      );
    });

    // --- "I've submitted it" ------------------------------------------------
    $card.on('click', '.adt-setup-checklist__mark', function (this: HTMLElement) {
      const $btn = $(this);
      const $step = $btn.closest('.adt-setup-checklist__step');
      $btn.prop('disabled', true);
      post({ action: 'adt_pfp_setup_checklist_update', task: 'submit', nonce: cardNonce }).then(
        (res: any) => {
          if (res && res.success) {
            const $title = $step.find('.adt-setup-checklist__step-title');
            $step.find('.adt-setup-checklist__step-actions').remove();
            markStepDone($step);
            refreshProgress();
            // The button the user was just on is now gone; move focus to the
            // step's own title so a keyboard/screen-reader user isn't dropped.
            $title.attr('tabindex', '-1').trigger('focus');
          } else {
            $btn.prop('disabled', false);
          }
        },
        () => $btn.prop('disabled', false)
      );
    });

    // --- Install & Activate a sister plugin ---------------------------------
    $card.on('click', '.adt-setup-checklist__install', function (this: HTMLElement) {
      const $btn = $(this);
      const $sister = $btn.closest('.adt-setup-checklist__sister');
      const $step = $btn.closest('.adt-setup-checklist__step');
      const slug = $sister.data('slug');
      const installing =
        '<span class="adt-setup-checklist__sister-state adt-setup-checklist__sister-state--installing">' +
        '<span class="adt-tw-icon-[lucide--loader-circle] chk-spin"></span>' +
        __('Installing…', 'woo-product-feed-pro') +
        '</span>';
      $btn.replaceWith(installing);

      post({
        action: 'adt_install_activate_plugin',
        plugin_slug: slug,
        silent: true,
        nonce: cfg.installNonce,
      }).then(
        (res: any) => {
          if (res && res.success) {
            $sister
              .find('.adt-setup-checklist__sister-state--installing')
              .replaceWith(
                '<span class="adt-setup-checklist__sister-state adt-setup-checklist__sister-state--installed">' +
                  '<span class="adt-tw-icon-[lucide--check]"></span>' +
                  __('Installed & Active', 'woo-product-feed-pro') +
                  '</span>'
              );
            markStepDone($step);
          } else {
            const msg = (res && res.data) || __('Installation failed.', 'woo-product-feed-pro');
            w.alert(String(msg));
            $sister
              .find('.adt-setup-checklist__sister-state--installing')
              .replaceWith(
                '<button type="button" class="adt-setup-checklist__btn adt-setup-checklist__btn--outline adt-setup-checklist__install">' +
                  '<span class="adt-tw-icon-[lucide--download]"></span>' +
                  __('Install & Activate', 'woo-product-feed-pro') +
                  '</button>'
              );
          }
        },
        () => {
          w.alert(__('A network error occurred. Please try again.', 'woo-product-feed-pro'));
          $sister
            .find('.adt-setup-checklist__sister-state--installing')
            .replaceWith(
              '<button type="button" class="adt-setup-checklist__btn adt-setup-checklist__btn--outline adt-setup-checklist__install">' +
                '<span class="adt-tw-icon-[lucide--download]"></span>' +
                __('Install & Activate', 'woo-product-feed-pro') +
                '</button>'
            );
        }
      );
    });

    /**
     * POST a checklist dismiss/restore update and route success/failure the
     * same way for both: only the success action and failure message differ.
     */
    function postChecklistUpdate(task: string, nonce: string, onSuccess: () => void, failureMessage: string) {
      post({ action: 'adt_pfp_setup_checklist_update', task, nonce }).then(
        (res: any) => (res && res.success ? onSuccess() : w.alert(failureMessage)),
        () => w.alert(__('A network error occurred. Please try again.', 'woo-product-feed-pro'))
      );
    }

    // --- Dismiss ------------------------------------------------------------
    $card.on('click', '.adt-setup-checklist__dismiss', function () {
      postChecklistUpdate(
        'dismiss',
        cardNonce,
        () => {
          $card.slideUp(150, () => $card.remove());
          showRestoreLink();
        },
        __('Could not dismiss the checklist. Please try again.', 'woo-product-feed-pro')
      );
    });

    /**
     * Inject the "Show setup checklist" button next to the page title if it
     * is not already present (after an in-place dismiss), then move focus to
     * it since the dismiss button it replaces was just removed from the DOM.
     */
    function showRestoreLink() {
      if ($('.adt-setup-checklist-restore').length) {
        return;
      }
      const $title = $('.wrap.adt-tw-wrapper h1').first();
      if (!$title.length) {
        return;
      }
      const link = $(
        '<button type="button" class="adt-setup-checklist-restore adt-tw-inline-flex adt-tw-items-center adt-tw-gap-1 adt-tw-text-sm adt-tw-font-semibold adt-tw-text-primary adt-tw-no-underline">' +
          '<span class="adt-tw-icon-[lucide--list-checks] adt-tw-w-4 adt-tw-h-4"></span>' +
          __('Show setup checklist', 'woo-product-feed-pro') +
          '</button>'
      );
      link.attr('data-nonce', cardNonce);
      $title.after(link);
      link.trigger('focus');
    }

    // --- Restore ------------------------------------------------------------
    $(d).on('click', '.adt-setup-checklist-restore', function (this: HTMLElement) {
      const nonce = $(this).data('nonce') || cfg.nonce;
      postChecklistUpdate(
        'restore',
        nonce,
        () => w.location.reload(),
        __('Could not restore the checklist. Please try again.', 'woo-product-feed-pro')
      );
    });
  });
})(window, document, jQuery);
