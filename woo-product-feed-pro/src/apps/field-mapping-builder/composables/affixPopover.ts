/***************************************************************************
 * Affix popover — the single open control
 ***************************************************************************
 *
 * Which `AffixControl` popover is open, shared across every instance.
 *
 * This lives in a MODULE, deliberately. A `ref` declared inside `<script setup>`
 * is created per instance — that block is the setup function body — so a
 * "shared" ref there gave every control its own copy and two popovers could
 * overlap. Only real module scope is shared.
 *
 * One open popover at a time is then structural, rather than something the
 * dismissal listeners have to get right: opening one drops the previous claim.
 */
import { ref } from 'vue';

const openControl = ref<symbol | null>(null);

export const useAffixPopover = (id: symbol) => ({
  isOpen: () => openControl.value === id,
  set: (open: boolean) => {
    openControl.value = open ? id : null;
  },
  release: () => {
    if (openControl.value === id) {
      openControl.value = null;
    }
  },
});
