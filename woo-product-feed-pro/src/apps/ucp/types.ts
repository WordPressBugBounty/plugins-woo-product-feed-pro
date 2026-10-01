export interface UcpConfig {
  rest_url: string;
  nonce: string;
  profile_url: string;
  orders_url: string;
}

export interface PreflightAction {
  label: string;
  url: string;
  external: boolean;
}

export interface PreflightCheck {
  id: string;
  label: string;
  passed: boolean;
  required: boolean;
  detail: string;
  fix: string;
  kind: 'auto' | 'manual';
  action: PreflightAction | null;
}

export interface PreflightResults {
  passed: boolean;
  ran_at: number;
  checks: PreflightCheck[];
}

export interface KeyMeta {
  hint?: string;
  created_at?: number;
}

export interface EligibilityStats {
  total: number;
  eligible: number;
  excluded: number;
  hard_excluded: number;
}

export interface StatusData {
  settings: Record<string, unknown>;
  is_enabled: boolean;
  is_live: boolean;
  is_serving: boolean;
  gates: Record<string, boolean>;
  profile_url: string;
  preflight: PreflightResults | Record<string, never>;
  account: { connected: boolean; server_url: string; connect_url: string };
  gateway: {
    detected: boolean;
    id: string;
    gateway_id: string;
    has_key: boolean;
    supports_completion: boolean;
    active_title: string;
  };
  api_keys: { staging: KeyMeta; production: KeyMeta };
  signing_key: { kid: string };
  stats: EligibilityStats;
  has_webhook_key?: boolean;
}

export interface EligibilityResolution {
  eligible: boolean;
  reason: string;
  kb_url: string;
}

export interface EligibilityItem {
  id: number;
  name: string;
  sku: string;
  type: string;
  override: string;
  resolution: EligibilityResolution;
}

export interface HardExclusion {
  types: string[];
  label: string;
  description: string;
  kb_url: string;
}

export interface RuleCondition {
  field: 'stock_status' | 'price' | 'category';
  operator: string;
  value: string | number[];
}

export interface EligibilityRules {
  match: string;
  conditions: RuleCondition[];
}

export interface CategoryOption {
  id: number;
  name: string;
}

export interface EligibilityData {
  items: EligibilityItem[];
  total: number;
  total_pages: number;
  exclusions: Record<string, HardExclusion>;
  rules: EligibilityRules;
  categories: CategoryOption[];
  stats: EligibilityStats;
}

export interface ProductHit {
  id: number;
  name: string;
  sku: string;
  label: string;
  override: string;
}

export interface AutoTopup {
  enabled: boolean;
  threshold_cents: number | null;
  amount_cents: number | null;
}

export interface AccountStatus {
  connected: boolean;
  reason?: string;
  account_id?: string;
  plan?: string;
  store?: { host: string; status: string };
  channel?: 'inactive' | 'active' | 'grace' | 'blocked';
  balance_status?: 'unfunded' | 'funded' | 'low' | 'grace';
  balance_cents?: number;
  auto_topup?: AutoTopup;
}

export interface UsageSummary {
  period?: { from: string; to: string };
  orders_count?: number;
  fees_deducted_cents?: number;
  balance_cents?: number;
  balance_status?: string;
  auto_topup?: AutoTopup;
  plan?: { id: string; flat_cents: number; pct_basis_points: number; cap_cents: number };
}

export interface AccountData {
  connected: boolean;
  status: AccountStatus | null;
  usage: UsageSummary | null;
  errors: { status?: string; usage?: string };
}
