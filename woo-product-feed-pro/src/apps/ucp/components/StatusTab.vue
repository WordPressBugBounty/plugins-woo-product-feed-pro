<template>
  <div v-if="status">
    <!-- The copy buttons confirm success only via :title and an aria-hidden
         glyph, which assistive tech never announces — this visually-hidden
         live region speaks the confirmation instead. -->
    <span class="adt-visually-hidden" role="status">
      {{ copied ? __('Copied!', 'woo-product-feed-pro') : '' }}
    </span>
    <!-- ============================== Status hero ============================== -->
    <div class="adt-card adt-hero" :class="`adt-hero--${hero.tone}`">
      <div class="adt-hero__main">
        <span class="adt-hero__tile" :class="`adt-hero__tile--${hero.tone}`">{{ hero.glyph }}</span>
        <div>
          <p class="adt-hero__eyebrow" :class="`adt-hero__eyebrow--${hero.tone}`">{{ hero.eyebrow }}</p>
          <h2>{{ hero.title }}</h2>
          <p class="adt-hero__desc">{{ hero.description }}</p>
        </div>
      </div>

      <div class="adt-hero__side">
        <!-- The switch is the feature on/off flag — pre-flight readiness is
             tracked separately and only gates serving, never the toggle. -->
        <label
          class="adt-switch"
          :title="status.is_enabled ? __('Disable AI Discovery', 'woo-product-feed-pro') : __('Enable AI Discovery', 'woo-product-feed-pro')"
        >
          <input
            type="checkbox"
            :checked="status.is_enabled"
            :disabled="saving"
            :aria-label="status.is_enabled ? __('Disable AI Discovery', 'woo-product-feed-pro') : __('Enable AI Discovery', 'woo-product-feed-pro')"
            @change="toggle('enabled', $event)"
          />
          <span class="adt-switch__slider"></span>
        </label>
        <span class="adt-hero__caption" :class="{ 'adt-hero__caption--on': status.is_enabled }">
          {{ status.is_enabled ? __('Enabled', 'woo-product-feed-pro') : __('Disabled', 'woo-product-feed-pro') }}
        </span>
        <label class="adt-hero__staging">
          <input
            type="checkbox"
            :checked="setting('staging_mode') === 'yes'"
            :disabled="saving"
            @change="toggle('staging_mode', $event)"
          />
          {{ __('Staging mode', 'woo-product-feed-pro') }}
        </label>
      </div>
    </div>

    <!-- ============================ Pre-flight checklist ============================ -->
    <div class="adt-card">
      <div class="adt-card__head">
        <div class="adt-card__title">
          <span class="adt-icon-tile">⚡</span>
          <div>
            <h3>{{ __('Pre-flight checklist', 'woo-product-feed-pro') }}</h3>
            <p class="adt-card__subtitle">
              {{ __('All required checks must pass before your store serves AI traffic', 'woo-product-feed-pro') }}
            </p>
          </div>
        </div>
        <button type="button" class="adt-btn adt-btn--outline" :disabled="running" @click="onRunPreflight">
          {{ running ? __('Running…', 'woo-product-feed-pro') : __('Run pre-flight', 'woo-product-feed-pro') }}
        </button>
      </div>

      <p v-if="!preflight" class="adt-empty">
        {{ __('Run the pre-flight checks to see what your store still needs.', 'woo-product-feed-pro') }}
      </p>

      <ul v-else class="adt-checks">
        <li v-for="check in preflight.checks" :key="check.id" class="adt-check">
          <span class="adt-status-icon" :class="`adt-status-icon--${checkTone(check)}`">
            {{ check.passed ? '✓' : checkTone(check) === 'warn' ? '⚠' : '✕' }}
          </span>
          <div class="adt-check__body">
            <strong class="adt-check__label">{{ check.label }}</strong>
            <p v-if="check.passed && check.detail" class="adt-check__detail">{{ check.detail }}</p>
            <p v-if="!check.passed" class="adt-check__detail">{{ check.fix || check.detail }}</p>
            <div v-if="!check.passed" class="adt-check__actions">
              <!-- The action URL arrives over REST; its scheme is validated
                   before binding, since Vue would happily render a
                   `javascript:` href. -->
              <a
                v-if="check.action && actionHref(check)"
                class="adt-btn adt-btn--small"
                :class="{ 'adt-btn--outline': checkTone(check) === 'warn' }"
                :href="actionHref(check)"
                :target="check.action.external ? '_blank' : undefined"
                rel="noreferrer"
              >
                {{ check.action.label }}
              </a>
              <label v-if="check.kind === 'manual'" class="adt-check__confirm">
                <input type="checkbox" :disabled="saving" @change="confirmManual(check.id, $event)" />
                {{ __('I confirm this is done', 'woo-product-feed-pro') }}
              </label>
            </div>
          </div>
          <span class="adt-pill" :class="`adt-pill--${check.passed ? 'pass' : checkTone(check)}`">
            {{
              check.passed
                ? __('Passed', 'woo-product-feed-pro')
                : checkTone(check) === 'warn'
                  ? __('Action needed', 'woo-product-feed-pro')
                  : __('Failed', 'woo-product-feed-pro')
            }}
          </span>
        </li>
      </ul>
    </div>

    <!-- ============================== Bottom panels ============================== -->
    <div class="adt-grid-2">
      <!-- Google Integration -->
      <div class="adt-card">
        <div class="adt-card__title">
          <span class="adt-icon-tile">⊕</span>
          <div>
            <h3>{{ __('Google Integration', 'woo-product-feed-pro') }}</h3>
            <p class="adt-card__subtitle">
              {{ __('Merchant Center & checkout endpoint credentials', 'woo-product-feed-pro') }}
            </p>
          </div>
        </div>

        <p class="adt-field-label">{{ __('Public UCP profile', 'woo-product-feed-pro') }}</p>
        <div class="adt-url-box">
          <span class="adt-mono">{{ status.profile_url }}</span>
          <button
            type="button"
            class="adt-copy"
            :title="copied === 'profile' ? __('Copied!', 'woo-product-feed-pro') : __('Copy', 'woo-product-feed-pro')"
            :aria-label="__('Copy public UCP profile URL', 'woo-product-feed-pro')"
            @click="copy(status.profile_url, 'profile')"
          >
            <span aria-hidden="true">{{ copied === 'profile' ? '✓' : '⧉' }}</span>
          </button>
        </div>

        <div class="adt-kv">
          <div v-for="env in environments" :key="env" class="adt-kv__row">
            <span>
              {{
                sprintf(
                  /* translators: %s: environment name, "Production" or "Staging" */
                  __('%s API key', 'woo-product-feed-pro'),
                  environmentLabel(env),
                )
              }}
            </span>
            <span class="adt-kv__value">
              <template v-if="newKeys[env]">
                <span class="adt-mono adt-new-key">{{ newKeys[env] }}</span>
                <button
                  type="button"
                  class="adt-copy"
                  :title="copied === env ? __('Copied!', 'woo-product-feed-pro') : __('Copy', 'woo-product-feed-pro')"
                  :aria-label="copyApiKeyAriaLabel(env)"
                  @click="copy(newKeys[env] ?? '', env)"
                >
                  <span aria-hidden="true">{{ copied === env ? '✓' : '⧉' }}</span>
                </button>
              </template>
              <span v-else-if="status.api_keys[env]?.hint" class="adt-mono">{{ status.api_keys[env]?.hint }}</span>
              <span v-else class="adt-kv__empty">{{ __('Not generated', 'woo-product-feed-pro') }}</span>
            </span>
          </div>
          <p v-if="newKeys.production || newKeys.staging" class="adt-key-warning">
            {{ __('Copy the new key now — it is shown only once.', 'woo-product-feed-pro') }}
          </p>
          <div class="adt-kv__row">
            <span>{{ __('Webhook partner ID', 'woo-product-feed-pro') }}</span>
            <span class="adt-kv__value">
              <input
                v-model="partnerId"
                type="text"
                class="adt-input adt-input--mono"
                :placeholder="__('partners/…', 'woo-product-feed-pro')"
                :aria-label="__('Webhook partner ID', 'woo-product-feed-pro')"
                @input="touchGoogleSettings"
              />
            </span>
          </div>
          <div class="adt-kv__row">
            <span>{{ __('Webhook API key', 'woo-product-feed-pro') }}</span>
            <span class="adt-kv__value">
              <input
                v-model="webhookKey"
                type="text"
                class="adt-input adt-input--mono"
                :placeholder="webhookKeyPlaceholder"
                :aria-label="__('Webhook API key', 'woo-product-feed-pro')"
                @input="touchGoogleSettings"
              />
            </span>
          </div>
          <div v-if="googleDirty" class="adt-kv__row adt-kv__row--actions">
            <span></span>
            <button type="button" class="adt-btn adt-btn--small" :disabled="saving" @click="saveGoogleSettings">
              {{ __('Save webhook settings', 'woo-product-feed-pro') }}
            </button>
          </div>
        </div>

        <div class="adt-card__footer">
          <a class="adt-btn adt-btn--outline" href="https://merchants.google.com/" target="_blank" rel="noreferrer">
            ↗&nbsp; {{ __('Open Merchant Center', 'woo-product-feed-pro') }}
          </a>
          <button type="button" class="adt-btn adt-btn--ghost" @click="showRotate = !showRotate">
            ⚿&nbsp; {{ __('Rotate keys', 'woo-product-feed-pro') }}
          </button>
        </div>
        <div v-if="showRotate" class="adt-rotate">
          <button
            v-for="env in environments"
            :key="env"
            type="button"
            class="adt-btn adt-btn--outline adt-btn--small"
            :disabled="saving"
            @click="onGenerateKey(env)"
          >
            {{
              status.api_keys[env]?.hint
                ? sprintf(
                    /* translators: %s: environment name, "Production" or "Staging" */
                    __('Regenerate %s key', 'woo-product-feed-pro'),
                    environmentLabel(env),
                  )
                : sprintf(
                    /* translators: %s: environment name, "Production" or "Staging" */
                    __('Generate %s key', 'woo-product-feed-pro'),
                    environmentLabel(env),
                  )
            }}
          </button>
        </div>
      </div>

      <!-- Payments -->
      <div class="adt-card">
        <div class="adt-card__title">
          <span class="adt-icon-tile">▤</span>
          <div>
            <h3>{{ __('Payments', 'woo-product-feed-pro') }}</h3>
            <p class="adt-card__subtitle">
              {{ __('Free in v1 — automated AI checkout is coming', 'woo-product-feed-pro') }}
            </p>
          </div>
        </div>

        <!-- Translatable copy with <strong> placeholders — see paymentsIntro. -->
        <!-- prettier-ignore -->
        <p class="adt-footnote adt-footnote--lead"><component :is="part.tag" v-for="(part, index) in paymentsIntro" :key="index">{{ part.text }}</component></p>

        <div class="adt-kv">
          <div class="adt-kv__row">
            <span>{{ __('Active gateway', 'woo-product-feed-pro') }}</span>
            <strong class="adt-kv__strong">{{ status.gateway.active_title || '—' }}</strong>
          </div>
          <div v-if="gatewayCompatible" class="adt-kv__row">
            <span>{{ __('Payment handler', 'woo-product-feed-pro') }}</span>
            <span class="adt-mono">com.google.pay</span>
          </div>
          <div class="adt-kv__row">
            <span>{{ __('AP2 checkout readiness', 'woo-product-feed-pro') }}</span>
            <span class="adt-pill" :class="gatewayCompatible ? 'adt-pill--pass' : 'adt-pill--warn'">
              {{ gatewayCompatible ? __('✓ Ready', 'woo-product-feed-pro') : __('Not yet — optional', 'woo-product-feed-pro') }}
            </span>
          </div>
        </div>

        <!-- Translatable copy with <strong> placeholders — see gatewayMissingFootnote. -->
        <!-- prettier-ignore -->
        <p v-if="!gatewayCompatible" class="adt-footnote"><component :is="part.tag" v-for="(part, index) in gatewayMissingFootnote" :key="index">{{ part.text }}</component></p>
        <p v-else class="adt-footnote">
          {{
            __(
              'Your Stripe gateway is ready — once AP2 checkout ships, agent payments settle through it using a deferred PaymentIntent, and 3-D Secure escalates to your normal checkout page.',
              'woo-product-feed-pro',
            )
          }}
        </p>
      </div>
    </div>

    <!-- ============================ AdTribes account ============================ -->
    <AccountCard
      :connected="status.account.connected"
      :connect-url="status.account.connect_url"
      @refresh="emit('refresh')"
      @notify="(message) => emit('notify', message)"
    />
  </div>
  <p v-else-if="busy">{{ __('Loading…', 'woo-product-feed-pro') }}</p>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { __, _n, sprintf } from '@wordpress/i18n';
import { generateApiKey, runPreflight, saveSettings } from '../api';
import { copyToClipboard } from '../clipboard';
import { richTextParts, safeHref, strongPairs } from '../rich-text';
import type { PreflightCheck, PreflightResults, StatusData } from '../types';
import AccountCard from './AccountCard.vue';

const props = defineProps<{
  status: StatusData | null;
  busy: boolean;
}>();

const emit = defineEmits<{
  refresh: [];
  notify: [message: string];
}>();

const environments = ['production', 'staging'] as const;

const running = ref(false);
const saving = ref(false);
const showRotate = ref(false);
const partnerId = ref('');
const webhookKey = ref('');
const newKeys = ref<Partial<Record<string, string>>>({});
const localPreflight = ref<PreflightResults | null>(null);

const setting = (key: string): string => String(props.status?.settings[key] ?? '');

// Set once the merchant edits either Google field, cleared again after a
// successful save. Pre-flight runs on mount and on enable and emits
// `refresh`, so the parent replaces `status` — and the seeding watcher below
// fires — while someone may be mid-keystroke in these inputs.
const googleTouched = ref(false);

const touchGoogleSettings = (): void => {
  googleTouched.value = true;
};

// The webhook API key is write-only: the server never returns the secret
// (only a `has_webhook_key` flag), so the field always starts empty and an
// empty value on save means "leave unchanged" — mirrors the API-key
// generate/rotate flow below, which also never re-displays a saved secret.
//
// Seed from the server only while the fields are untouched: re-seeding on
// every status replacement would throw away in-progress typing whenever a
// background pre-flight happened to finish first.
watch(
  () => props.status,
  (value) => {
    if (value && !googleTouched.value) {
      partnerId.value = String(value.settings.google_partner_id ?? '');
      webhookKey.value = '';
    }
  },
  { immediate: true },
);

const webhookKeyPlaceholder = computed(() =>
  props.status?.has_webhook_key ? '••••••••' : __('key', 'woo-product-feed-pro'),
);

const environmentLabel = (env: string): string =>
  env === 'production' ? __('Production', 'woo-product-feed-pro') : __('Staging', 'woo-product-feed-pro');

const copyApiKeyAriaLabel = (env: string): string =>
  sprintf(
    /* translators: %s: environment name, "Production" or "Staging" */
    __('Copy %s API key', 'woo-product-feed-pro'),
    environmentLabel(env),
  );

const googleDirty = computed(
  () => partnerId.value !== String(props.status?.settings.google_partner_id ?? '') || webhookKey.value !== '',
);

const preflight = computed<PreflightResults | null>(() => {
  if (localPreflight.value) {
    return localPreflight.value;
  }
  const stored = props.status?.preflight;
  return stored && 'checks' in stored ? (stored as PreflightResults) : null;
});

const failedCount = computed(
  () => preflight.value?.checks.filter((check) => check.required && !check.passed).length ?? 0,
);

const preflightPassed = computed(() => preflight.value?.passed === true);
const gatewayCompatible = computed(
  () =>
    props.status?.gateway.detected === true &&
    props.status?.gateway.has_key === true &&
    props.status?.gateway.supports_completion === true,
);

// These paragraphs mix translatable copy with <strong> emphasis, so the
// bold segments are passed as %1$s/%2$s placeholders (sprintf) instead of
// splitting the sentence into multiple __() fragments, keeping the string
// whole and readable for translators.
// Emphasis is resolved into rendered <strong> elements rather than an HTML
// string fed to v-html, so a tampered translation can only produce text.
const paymentsIntro = computed(() =>
  richTextParts(
    /* translators: %1$s/%2$s: opening/closing <strong> tag around "discover and recommend your products"; %3$s/%4$s: opening/closing <strong> tag around "totally free"; %5$s/%6$s: opening/closing <strong> tag around "AP2 protocol" */
    __(
      "In v1, AI Discovery is all about helping AI surfaces %1$sdiscover and recommend your products%2$s — and it's %3$stotally free%4$s. Support for fully automated AI checkout via the %5$sAP2 protocol%6$s is coming soon; when it lands, agent payments will settle through your own Stripe gateway.",
      'woo-product-feed-pro',
    ),
    strongPairs(3),
  ),
);

const gatewayMissingFootnote = computed(() =>
  richTextParts(
    /* translators: %1$s/%2$s: opening/closing <strong> tag around "FunnelKit Stripe"; %3$s/%4$s: opening/closing <strong> tag around "WooCommerce Stripe Gateway" */
    __(
      "To be ready for AP2 checkout, install & activate %1$sFunnelKit Stripe%2$s or the %3$sWooCommerce Stripe Gateway%4$s. This is optional for now and doesn't affect AI Discovery.",
      'woo-product-feed-pro',
    ),
    strongPairs(2),
  ),
);

// Pre-flight action URLs come back over REST; validate the scheme before it
// reaches an anchor rather than trusting the server to have escaped it.
const actionHref = (check: PreflightCheck): string | undefined => safeHref(check.action?.url);

const hero = computed(() => {
  if (props.status?.is_live) {
    return {
      tone: 'live',
      glyph: '✓',
      eyebrow: __('● Live', 'woo-product-feed-pro'),
      title: __('Your products are discoverable by AI shoppers', 'woo-product-feed-pro'),
      description: __(
        'All pre-flight checks pass and your UCP profile is being served. Eligible products can be found and recommended by AI surfaces like Google AI Mode & Gemini — and once AP2 checkout ships, bought there too.',
        'woo-product-feed-pro',
      ),
    };
  }

  if (!props.status?.is_enabled) {
    return {
      tone: 'off',
      glyph: '✦',
      eyebrow: __('Disabled', 'woo-product-feed-pro'),
      title: __('Get your products discovered by AI', 'woo-product-feed-pro'),
      description: __(
        'Shoppers increasingly ask AI — Google AI Mode, Gemini and other assistants — what to buy. AI Discovery makes your products visible to those AI surfaces so they can find and recommend what you sell, using the product data you already have. It’s totally free in v1, with fully automated AI checkout (AP2) coming soon. Flip the switch to get started.',
        'woo-product-feed-pro',
      ),
    };
  }

  if (preflight.value && !preflightPassed.value) {
    return {
      tone: 'failed',
      glyph: '⚠',
      eyebrow: __('Pre-flight failed', 'woo-product-feed-pro'),
      title: sprintf(
        /* translators: %d: number of failed required pre-flight checks */
        _n(
          '%d issue to fix before your store serves AI traffic',
          '%d issues to fix before your store serves AI traffic',
          failedCount.value,
          'woo-product-feed-pro',
        ),
        failedCount.value,
      ),
      description: __(
        'AI Discovery is enabled, but your store isn’t serving AI surfaces yet. Fix the highlighted items below — each has a one-click action — and re-run the checks. This protects your store from wrong-total or oversold orders.',
        'woo-product-feed-pro',
      ),
    };
  }

  if (!preflight.value) {
    return {
      tone: 'ready',
      glyph: '…',
      eyebrow: __('Checking your store', 'woo-product-feed-pro'),
      title: __('AI Discovery is on — confirming your store is ready', 'woo-product-feed-pro'),
      description: __(
        'The pre-flight checks are running automatically to confirm your store meets the AI surfaces’ requirements. Results appear below in a few seconds.',
        'woo-product-feed-pro',
      ),
    };
  }

  return {
    tone: 'ready',
    glyph: '…',
    eyebrow: __('Almost there', 'woo-product-feed-pro'),
    title: __('Pre-flight passed — a few confirmations left', 'woo-product-feed-pro'),
    description: __(
      'Complete the remaining confirmations below — connect your AdTribes account, link Merchant Center and acknowledge Google approval — and your store starts serving AI surfaces automatically.',
      'woo-product-feed-pro',
    ),
  };
});

const checkTone = (check: PreflightCheck): 'pass' | 'fail' | 'warn' => {
  if (check.passed) {
    return 'pass';
  }
  return check.kind === 'manual' || !check.required ? 'warn' : 'fail';
};

const onRunPreflight = async () => {
  running.value = true;
  try {
    localPreflight.value = await runPreflight();
    emit('refresh');
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Pre-flight failed to run.', 'woo-product-feed-pro'));
  } finally {
    running.value = false;
  }
};

// When AI Discovery is enabled but pre-flight has never run (or was just
// enabled), run it automatically instead of waiting for a click.
const autoRan = ref(false);
watch(
  () => props.status,
  (value) => {
    if (value?.is_enabled && !preflight.value && !running.value && !autoRan.value) {
      autoRan.value = true;
      void onRunPreflight();
    }
  },
  { immediate: true },
);

const save = async (payload: Record<string, unknown>): Promise<boolean> => {
  saving.value = true;
  try {
    await saveSettings(payload);
    emit('refresh');
    return true;
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Saving failed.', 'woo-product-feed-pro'));
    return false;
  } finally {
    saving.value = false;
  }
};

const toggle = async (key: string, event: Event) => {
  const checked = (event.target as HTMLInputElement).checked;
  await save({ [key]: checked });

  // Re-check readiness on every enable instead of waiting for a click.
  if (key === 'enabled' && checked && !running.value) {
    autoRan.value = true;
    void onRunPreflight();
  }
};

const confirmManual = async (key: string, event: Event) => {
  await save({ [key]: (event.target as HTMLInputElement).checked });
  await onRunPreflight();
};

const saveGoogleSettings = async () => {
  const payload: Record<string, unknown> = { google_partner_id: partnerId.value };
  if (webhookKey.value !== '') {
    payload.google_webhook_key = webhookKey.value;
  }

  if (!(await save(payload))) {
    return; // Keep the edits — and the dirty state — so they can retry.
  }

  // Saved: hand the fields back to the server, so the refreshed status
  // re-seeds them and the write-only webhook key goes back to empty.
  googleTouched.value = false;
  webhookKey.value = '';
};

const onGenerateKey = async (environment: 'staging' | 'production') => {
  saving.value = true;
  try {
    const result = await generateApiKey(environment);
    newKeys.value = { ...newKeys.value, [environment]: result.key };
    emit('refresh');
  } catch (e) {
    emit('notify', e instanceof Error ? e.message : __('Key generation failed.', 'woo-product-feed-pro'));
  } finally {
    saving.value = false;
  }
};

const copied = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

onUnmounted(() => clearTimeout(copiedTimer));

const copy = async (text: string, target: string) => {
  // copyToClipboard falls back to execCommand outside secure contexts —
  // navigator.clipboard does not exist there at all.
  if (await copyToClipboard(text)) {
    copied.value = target;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => {
      copied.value = null;
    }, 2000);
  } else {
    emit('notify', __('Could not copy to the clipboard.', 'woo-product-feed-pro'));
  }
};
</script>

<style scoped>
.adt-visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* Hero */
.adt-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 26px 28px;
}
.adt-hero--live {
  background: #effaf3;
  border-color: #bfe8cf;
}
.adt-hero--failed {
  background: #fdf1f1;
  border-color: #f3cdce;
}
.adt-hero__main {
  display: flex;
  gap: 18px;
  align-items: flex-start;
}
.adt-hero__tile {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  font-weight: 700;
  flex: none;
}
.adt-hero__tile--live {
  background: var(--adt-green);
  color: #fff;
}
.adt-hero__tile--failed {
  background: var(--adt-red);
  color: #fff;
}
.adt-hero__tile--off,
.adt-hero__tile--ready {
  background: #ececf4;
  color: #6f7290;
}
.adt-hero__eyebrow {
  margin: 0 0 2px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--adt-muted);
}
.adt-hero__eyebrow--live {
  color: var(--adt-green);
}
.adt-hero__eyebrow--failed {
  color: var(--adt-red);
}
.adt-hero__desc {
  margin: 6px 0 0;
  max-width: 640px;
}
.adt-hero__side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
  flex: none;
}
.adt-hero__caption {
  font-size: 13px;
  color: var(--adt-muted);
}
.adt-hero__caption--on {
  color: var(--adt-navy);
  font-weight: 700;
}
.adt-hero__staging {
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 12px;
  color: var(--adt-muted);
}

/* Checklist */
.adt-checks {
  margin: 12px 0 0;
  list-style: none;
  padding: 0;
}
.adt-check {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  padding: 14px 4px;
  border-top: 1px solid #f2f2f8;
}
.adt-check__body {
  flex: 1;
}
.adt-check__label {
  color: var(--adt-navy);
  font-size: 15px;
}
.adt-check__detail {
  margin: 3px 0 0;
  color: var(--adt-muted);
}
.adt-check__actions {
  display: flex;
  gap: 14px;
  align-items: center;
  margin-top: 10px;
}
.adt-check__confirm {
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 13px;
  color: var(--adt-muted);
}
.adt-empty {
  color: var(--adt-muted);
}

/* Google panel */
.adt-field-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--adt-muted);
  margin: 18px 0 6px;
}
.adt-url-box {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--adt-border);
  border-radius: 9px;
  padding: 10px 14px;
  background: #fbfbfd;
  overflow: hidden;
}
.adt-copy {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 16px;
  color: var(--adt-muted);
  flex: none;
}
.adt-copy:hover {
  color: var(--adt-pink);
}
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
.adt-kv__row--actions {
  border-bottom: none;
  padding-bottom: 0;
}
.adt-kv__value {
  display: flex;
  align-items: center;
  gap: 6px;
}
.adt-kv__strong {
  color: var(--adt-navy);
}
.adt-kv__empty {
  color: var(--adt-muted);
  font-style: italic;
}
.adt-new-key {
  background: #fcf9e8;
  padding: 3px 6px;
  border-radius: 5px;
  word-break: break-all;
}
.adt-key-warning {
  color: var(--adt-orange);
  font-size: 13px;
  margin: 6px 0 0;
}
.adt-input--mono {
  font-family: Menlo, Consolas, Monaco, monospace;
  font-size: 13px;
  text-align: right;
  border-color: transparent;
  background: transparent;
  max-width: 220px;
}
.adt-input--mono:hover,
.adt-input--mono:focus {
  border-color: var(--adt-border);
  background: #fff;
}
.adt-card__footer {
  display: flex;
  gap: 14px;
  align-items: center;
  margin-top: 18px;
}
.adt-rotate {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

/* Payments */
.adt-footnote {
  color: var(--adt-muted);
  margin-top: 14px;
}
.adt-footnote--lead {
  margin: 14px 0 4px;
}
</style>
