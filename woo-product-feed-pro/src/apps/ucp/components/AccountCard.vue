<template>
  <div class="adt-card">
    <div class="adt-card__head">
      <div class="adt-card__title">
        <span class="adt-icon-tile">◍</span>
        <div>
          <h3>{{ __('AdTribes Account', 'woo-product-feed-pro') }}</h3>
          <p class="adt-card__subtitle">
            {{ __('Verifies AI-agent checkout payments and manages your prepaid credits', 'woo-product-feed-pro') }}
          </p>
        </div>
      </div>
      <span v-if="connected" class="adt-pill adt-pill--pass">{{ __('Connected', 'woo-product-feed-pro') }}</span>
    </div>

    <!-- ============================== Not connected ============================== -->
    <template v-if="!connected">
      <p class="adt-footnote adt-footnote--lead">
        {{
          __(
            'AI Discovery itself is free and needs no account. Connecting prepares your store for AP2 agentic checkout: every AI-agent payment is verified through your AdTribes account, billed per order from a prepaid credit balance.',
            'woo-product-feed-pro',
          )
        }}
      </p>
      <p class="adt-footnote">
        {{
          __(
            'You can sign up during the connection — just an email and a one-time code. The checkout channel activates after your first credit top-up.',
            'woo-product-feed-pro',
          )
        }}
      </p>
      <div class="adt-card__footer">
        <a class="adt-btn" :href="connectUrl">{{ __('Connect AdTribes account', 'woo-product-feed-pro') }}</a>
      </div>
    </template>

    <!-- ================================ Connected ================================ -->
    <template v-else>
      <p v-if="loading" class="adt-empty">{{ __('Loading account…', 'woo-product-feed-pro') }}</p>

      <template v-else>
        <div class="adt-kv">
          <div class="adt-kv__row">
            <span>{{ __('Credit balance', 'woo-product-feed-pro') }}</span>
            <span class="adt-kv__value">
              <strong class="adt-kv__strong">{{ formatCents(balanceCents) }}</strong>
              <span v-if="balanceStatus" class="adt-pill" :class="`adt-pill--${balanceTone}`">{{ balanceLabel }}</span>
            </span>
          </div>
          <div class="adt-kv__row">
            <span>{{ __('Checkout channel', 'woo-product-feed-pro') }}</span>
            <span class="adt-pill" :class="`adt-pill--${channelTone}`">{{ channelLabel }}</span>
          </div>
          <div v-if="account?.usage" class="adt-kv__row">
            <span>{{ __('Orders this period', 'woo-product-feed-pro') }}</span>
            <strong class="adt-kv__strong">{{ (account.usage.orders_count ?? 0).toLocaleString() }}</strong>
          </div>
          <div v-if="account?.usage" class="adt-kv__row">
            <span>{{ __('Fees this period', 'woo-product-feed-pro') }}</span>
            <strong class="adt-kv__strong">{{ formatCents(account.usage.fees_deducted_cents ?? 0) }}</strong>
          </div>
        </div>

        <p v-if="channel === 'inactive'" class="adt-footnote">
          {{
            __(
              'Your account is connected, but the checkout channel stays inactive until your first credit top-up.',
              'woo-product-feed-pro',
            )
          }}
        </p>

        <!-- Auto-topup -->
        <div class="adt-autotopup">
          <label class="adt-autotopup__toggle">
            <input v-model="autoTopupEnabled" type="checkbox" :disabled="working" />
            {{ __('Automatically top up when the balance runs low', 'woo-product-feed-pro') }}
          </label>
          <div v-if="autoTopupEnabled" class="adt-autotopup__fields">
            <label>
              {{ __('When balance falls below ($)', 'woo-product-feed-pro') }}
              <input v-model.number="thresholdDollars" type="number" min="0" step="1" class="adt-input" :disabled="working" />
            </label>
            <label>
              {{ __('Top up by ($)', 'woo-product-feed-pro') }}
              <input v-model.number="amountDollars" type="number" min="5" step="1" class="adt-input" :disabled="working" />
            </label>
          </div>
          <button
            v-if="autoTopupDirty"
            type="button"
            class="adt-btn adt-btn--small"
            :disabled="working"
            @click="onSaveAutoTopup"
          >
            {{ __('Save auto top-up', 'woo-product-feed-pro') }}
          </button>
        </div>

        <p v-if="fetchError" class="adt-footnote">{{ fetchError }}</p>

        <div class="adt-card__footer">
          <button type="button" class="adt-btn" :disabled="working" @click="onTopup">
            {{ __('Top up credits', 'woo-product-feed-pro') }}
          </button>
          <button type="button" class="adt-btn adt-btn--ghost" :disabled="working" @click="onDisconnect">
            {{ __('Disconnect', 'woo-product-feed-pro') }}
          </button>
        </div>
        <p class="adt-footnote">
          {{ __('Credits are purchased on adtribes.io — your balance updates within a minute after checkout. Auto top-up charges the card saved from your last credit purchase.', 'woo-product-feed-pro') }}
        </p>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { __ } from '@wordpress/i18n';
import { createTopupLink, disconnectAccount, getAccount, saveAutoTopup } from '../api';
import type { AccountData, AutoTopup } from '../types';

const props = defineProps<{
  connected: boolean;
  connectUrl: string;
}>();

const emit = defineEmits<{
  refresh: [];
  notify: [message: string];
}>();

const account = ref<AccountData | null>(null);
const loading = ref(false);
const working = ref(false);

const autoTopupEnabled = ref(false);
const thresholdDollars = ref(10);
const amountDollars = ref(25);
const savedAutoTopup = ref<AutoTopup | null>(null);

// v1 bills USD only (contract 1.0.0-rc.2), so balance formatting is fixed.
const formatCents = (cents: number): string =>
  (cents / 100).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

const balanceCents = computed(() => account.value?.status?.balance_cents ?? account.value?.usage?.balance_cents ?? 0);
const balanceStatus = computed(() => account.value?.status?.balance_status ?? '');
const channel = computed(() => account.value?.status?.channel ?? '');

const balanceTone = computed(() => {
  if (balanceStatus.value === 'funded') return 'pass';
  if (balanceStatus.value === 'grace') return 'fail';
  return 'warn';
});

const balanceLabel = computed(() => {
  switch (balanceStatus.value) {
    case 'funded':
      return __('Funded', 'woo-product-feed-pro');
    case 'low':
      return __('Low', 'woo-product-feed-pro');
    case 'grace':
      return __('Negative — grace period', 'woo-product-feed-pro');
    case 'unfunded':
      return __('Not funded yet', 'woo-product-feed-pro');
    default:
      return balanceStatus.value;
  }
});

const channelTone = computed(() => {
  if (channel.value === 'active') return 'pass';
  if (channel.value === 'blocked') return 'fail';
  return 'warn';
});

const channelLabel = computed(() => {
  switch (channel.value) {
    case 'active':
      return __('Active', 'woo-product-feed-pro');
    case 'inactive':
      return __('Inactive — awaiting first top-up', 'woo-product-feed-pro');
    case 'grace':
      return __('Grace period', 'woo-product-feed-pro');
    case 'blocked':
      return __('Blocked', 'woo-product-feed-pro');
    default:
      return __('Unknown', 'woo-product-feed-pro');
  }
});

const fetchError = computed(() => account.value?.errors?.status ?? account.value?.errors?.usage ?? '');

const autoTopupDirty = computed(() => {
  const saved = savedAutoTopup.value;
  const savedEnabled = saved?.enabled === true;
  if (autoTopupEnabled.value !== savedEnabled) {
    return true;
  }
  if (!autoTopupEnabled.value) {
    return false;
  }
  return (
    Math.round(thresholdDollars.value * 100) !== (saved?.threshold_cents ?? 0) ||
    Math.round(amountDollars.value * 100) !== (saved?.amount_cents ?? 0)
  );
});

const applyAutoTopup = (value: AutoTopup | null | undefined) => {
  savedAutoTopup.value = value ?? null;
  autoTopupEnabled.value = value?.enabled === true;
  if (value?.threshold_cents) {
    thresholdDollars.value = value.threshold_cents / 100;
  }
  if (value?.amount_cents) {
    amountDollars.value = value.amount_cents / 100;
  }
};

const load = async () => {
  loading.value = true;
  try {
    account.value = await getAccount();
    applyAutoTopup(account.value.status?.auto_topup ?? account.value.usage?.auto_topup);
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Failed to load the account status.', 'woo-product-feed-pro'));
  } finally {
    loading.value = false;
  }
};

const onTopup = async () => {
  working.value = true;
  // Open the tab synchronously with the click so popup blockers (Safari,
  // strict Firefox) don't eat it while the link request is in flight; the
  // real URL is assigned once it arrives.
  const storeTab = window.open('', '_blank');
  try {
    const link = await createTopupLink();
    if (link.url) {
      // The adtribes.io storefront (rc.4). Checkout happens there; the
      // balance lands asynchronously via the store's grant push.
      if (storeTab) {
        storeTab.opener = null;
        storeTab.location.href = link.url;
      } else {
        // Popup blocked anyway — fall back to navigating this tab.
        window.location.assign(link.url);
        return;
      }
      emit(
        'notify',
        __('Complete the purchase on adtribes.io — your balance updates within a minute after checkout.', 'woo-product-feed-pro'),
      );
      return;
    }
    storeTab?.close();
    emit('notify', __('The account server did not return a top-up link.', 'woo-product-feed-pro'));
  } catch (e) {
    storeTab?.close();
    emit('notify', e instanceof Error ? e.message : __('Starting the top-up failed.', 'woo-product-feed-pro'));
  } finally {
    working.value = false;
  }
};

const onSaveAutoTopup = async () => {
  working.value = true;
  try {
    const result = await saveAutoTopup(
      autoTopupEnabled.value,
      Math.round(thresholdDollars.value * 100),
      Math.round(amountDollars.value * 100),
    );
    applyAutoTopup(result.auto_topup);
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Saving auto top-up failed.', 'woo-product-feed-pro'));
  } finally {
    working.value = false;
  }
};

const onDisconnect = async () => {
  if (
    !window.confirm(
      __(
        'Disconnect this store from your AdTribes account? Agentic checkout verification stops working until you reconnect.',
        'woo-product-feed-pro',
      ),
    )
  ) {
    return;
  }

  working.value = true;
  try {
    await disconnectAccount();
    account.value = null;
    emit('refresh');
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Disconnecting failed.', 'woo-product-feed-pro'));
  } finally {
    working.value = false;
  }
};

onMounted(() => {
  if (props.connected) {
    void load();
  }
});
</script>

<style scoped>
.adt-kv {
  margin-top: 8px;
}
.adt-kv__row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 11px 0;
  border-bottom: 1px solid #f2f2f8;
}
.adt-kv__value {
  display: flex;
  align-items: center;
  gap: 8px;
}
.adt-kv__strong {
  color: var(--adt-navy);
}
.adt-footnote {
  color: var(--adt-muted);
  margin-top: 14px;
}
.adt-footnote--lead {
  margin: 14px 0 4px;
}
.adt-empty {
  color: var(--adt-muted);
}
.adt-card__footer {
  display: flex;
  gap: 14px;
  align-items: center;
  margin-top: 18px;
  flex-wrap: wrap;
}
.adt-autotopup {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-start;
}
.adt-autotopup__toggle {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 13px;
  color: var(--adt-muted);
}
.adt-autotopup__fields {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}
.adt-autotopup__fields label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: var(--adt-muted);
}
.adt-autotopup__fields .adt-input {
  max-width: 120px;
}
</style>
