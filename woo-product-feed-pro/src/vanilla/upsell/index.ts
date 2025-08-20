// Declare jQuery variables
declare var jQuery: any;
declare var vex: any;

import './style.scss';

(function (w, d, $) {
  // DOM ready
  $(function () {
    // Global function for the upsell modal
    w.adtObj.showEliteUpsellModal = function (id: string) {
      const upsellL10n = w.adtObj.upsellL10n[id] || w.adtObj.upsellL10n.default;
      const content = upsellL10n.content;

      vex.dialog.alert({
        className: 'vex-theme-plain adt-pfp-upsell-modal',
        unsafeMessage: content,
      });
    };
  });
})(window, document, jQuery);
