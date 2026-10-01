import { __, sprintf } from '@wordpress/i18n';
import type {
  AccountData,
  AutoTopup,
  EligibilityData,
  EligibilityRules,
  EligibilityStats,
  PreflightResults,
  ProductHit,
  StatusData,
  UcpConfig,
} from './types';

declare global {
  interface Window {
    adtObjUcp?: UcpConfig;
  }
}

const config: UcpConfig = window.adtObjUcp ?? { rest_url: '', nonce: '', profile_url: '', orders_url: '' };

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${config.rest_url}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': config.nonce,
      ...(options.headers ?? {}),
    },
  });

  if (!response.ok) {
    /* translators: %d: HTTP status code */
    let message: string = sprintf(__('HTTP %d', 'woo-product-feed-pro'), response.status);
    try {
      const body = (await response.json()) as { message?: string };
      if (body.message) {
        message = body.message;
      }
    } catch {
      // Non-JSON error body; keep the HTTP status message.
    }
    throw new Error(message);
  }

  return (await response.json()) as T;
}

export const getConfig = (): UcpConfig => config;

export const getStatus = (): Promise<StatusData> => request<StatusData>('/status');

export const runPreflight = (): Promise<PreflightResults> =>
  request<PreflightResults>('/preflight', { method: 'POST', body: '{}' });

export const saveSettings = (settings: Record<string, unknown>): Promise<{ settings: Record<string, string> }> =>
  request<{ settings: Record<string, string> }>('/settings', { method: 'POST', body: JSON.stringify(settings) });

export const generateApiKey = (
  environment: 'staging' | 'production',
): Promise<{ environment: string; key: string; meta: { hint?: string } }> =>
  request<{ environment: string; key: string; meta: { hint?: string } }>('/api-keys', {
    method: 'POST',
    body: JSON.stringify({ environment }),
  });

export const getEligibility = (page: number, search: string): Promise<EligibilityData> =>
  request<EligibilityData>(`/eligibility?page=${page}&search=${encodeURIComponent(search)}`);

export const setEligibility = (productIds: number[], override: string): Promise<{ updated: number }> =>
  request<{ updated: number }>('/eligibility', {
    method: 'POST',
    body: JSON.stringify({ product_ids: productIds, override }),
  });

export const saveRules = (rules: EligibilityRules): Promise<{ rules: EligibilityRules; stats: EligibilityStats }> =>
  request<{ rules: EligibilityRules; stats: EligibilityStats }>('/eligibility/rules', {
    method: 'POST',
    body: JSON.stringify({ rules }),
  });

export const searchProducts = (search: string): Promise<{ items: ProductHit[] }> =>
  request<{ items: ProductHit[] }>(`/products?search=${encodeURIComponent(search)}`);

export const getAccount = (): Promise<AccountData> => request<AccountData>('/account');

export const createTopupLink = (): Promise<{ url: string }> =>
  request<{ url: string }>('/account/topup', { method: 'POST', body: '{}' });

export const saveAutoTopup = (
  enabled: boolean,
  thresholdCents: number,
  amountCents: number,
): Promise<{ auto_topup: AutoTopup }> =>
  request<{ auto_topup: AutoTopup }>('/account/auto-topup', {
    method: 'POST',
    body: JSON.stringify({ enabled, threshold_cents: thresholdCents, amount_cents: amountCents }),
  });

export const disconnectAccount = (): Promise<{ disconnected: boolean }> =>
  request<{ disconnected: boolean }>('/account/disconnect', { method: 'POST', body: '{}' });
