import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { __, sprintf } from '@wordpress/i18n';
import api from '@/api';
import type {
  FeedAttribute,
  AttributeCatalog,
  AttributeSource,
  ChannelAttributeCatalog,
  ChannelAttributeDefinition,
  SubAttribute,
  SubAttributeGroup,
  SubAttributeRegistry,
  SubAttributeSource,
  FieldMappingsApiResponse,
  FieldMappingPreviewResponse,
  FeedNameValidationResult,
  PreviewLine,
  PreviewProductOption,
  RequirementLevel,
  SpecRow,
} from '../types';

// Group label for rows the channel catalog does not describe: custom fields, and
// rows left behind when a feed's channel changed.
const CUSTOM_GROUP_LABEL = __('Custom fields', 'woo-product-feed-pro');

/**
 * Resolve the catalog's `{{CURRENCY}}` placeholder.
 */
const replaceCurrency = (value: string | undefined, currency: string): string =>
  (value ?? '').replace(/\{\{CURRENCY\}\}/g, currency);

/**
 * Collect the output tags a conditionally required attribute depends on.
 *
 * `g:mpn` is required only until `g:gtin` is mapped, so `g:gtin` is a gate tag.
 * A gate tag is as important to the user as the attribute it relaxes — hiding it
 * would leave a Required badge with no visible way to satisfy it — so it earns
 * the same pre-population and the same place outside the collapsed tail.
 *
 * Both conditional keys name one: `required_unless` names the tag that RELAXES
 * an attribute, `required_when` the tag that ARMS it. The user needs to reach
 * either one, so both are collected. `g:availability` happens to be `required`
 * already, so today this adds nothing — it stops the next `required_when` rule
 * from hiding its own gate behind the collapsed tail.
 */
const collectGateTags = (catalog: ChannelAttributeCatalog): Set<string> => {
  const tags = new Set<string>();

  for (const groupName in catalog) {
    const group = catalog[groupName];

    for (const label in group) {
      const definition = group[label];

      const relaxes = definition?.required_unless;
      if (typeof relaxes === 'string' && relaxes) tags.add(relaxes);

      const arms = definition?.required_when?.feed_name;
      if (typeof arms === 'string' && arms) tags.add(arms);
    }
  }

  return tags;
};

/**
 * Split a catalog `woo_suggest` value into a source and its value.
 *
 * The catalog encodes non-field suggestions as markers — `static_value:X`,
 * `page:X`, `post:X` — and a bare key otherwise.
 */
const parseWooSuggest = (suggest: string): { source: AttributeSource; sourceValue: string } => {
  if (suggest.startsWith('static_value:')) {
    return { source: 'static', sourceValue: suggest.slice('static_value:'.length) };
  }
  if (suggest.startsWith('page:')) {
    return { source: 'page_url', sourceValue: suggest.slice('page:'.length) };
  }
  if (suggest.startsWith('post:')) {
    return { source: 'post_url', sourceValue: suggest.slice('post:'.length) };
  }
  return { source: 'field', sourceValue: suggest };
};

/*
 * Typed as the unions, NOT as `readonly string[]`.
 *
 * These mirror `Feed_Attributes_Migrator::SOURCES` and `::SUB_SOURCES`. When
 * `page_url`/`post_url` were added to the sub-attribute set, the TS union, the
 * PHP constant and the resolver were all updated and this array was not — and
 * because it was typed `string[]`, nothing failed. A saved `page_url` sub-row
 * then failed the guard in `normalizeSubAttribute()`, was rewritten to `field`
 * on the next load, and the following save persisted that, so the resolver
 * emitted nothing for it.
 *
 * Typed as the union, the same omission is a compile error.
 */
const ATTRIBUTE_SOURCES: readonly AttributeSource[] = ['field', 'static', 'page_url', 'post_url'];
const SUB_ATTRIBUTE_SOURCES: readonly SubAttributeSource[] = [
  'zone',
  'field',
  'static',
  'empty',
  'page_url',
  'post_url',
];

/**
 * Type guard: whether an unknown API value is a valid top-level attribute source.
 */
const isAttributeSource = (value: unknown): value is AttributeSource =>
  // The widening cast is on the LOOKUP, not on the array: the array stays typed
  // as the union so a missing member fails the build.
  typeof value === 'string' && (ATTRIBUTE_SOURCES as readonly string[]).includes(value);

/**
 * Type guard: whether an unknown API value is a valid sub-attribute source.
 */
const isSubAttributeSource = (value: unknown): value is SubAttributeSource =>
  typeof value === 'string' && (SUB_ATTRIBUTE_SOURCES as readonly string[]).includes(value);

// Allowed characters for a custom feed name: letters (Cyrillic included for
// Yandex/Zbozi/Heureka), underscores and hyphens. Module scope so the literal is
// compiled once instead of on every call inside the per-row validation loop.
const CUSTOM_FEED_NAME_ALLOWED = /^[a-zA-Zа-яА-ЯёЁ_-]*$/;

// `PARAM_` names bypass the character check entirely — Zbozi/Heureka PARAM names
// may carry arbitrary characters (e.g. Czech diacritics).
const CUSTOM_FEED_NAME_ANY = /.*/;

/**
 * Mirror of `Feed_Writer_XML::XML_NAME_PATTERN`.
 *
 * The writer SKIPS a sub-attribute whose tag is not a valid XML element name,
 * so a tag this app accepts but that pattern rejects would save and then emit
 * nothing. Keep the two in step.
 */
const XML_ELEMENT_NAME = /^(?:[A-Za-z_][\w.-]*:)?[A-Za-z_][\w.-]*$/;

/** An XML namespace prefix: the part before the colon in `g:price`. */
const XML_NAMESPACE_PREFIX = /^[A-Za-z_][\w.-]*$/;

const CUSTOM_FEED_NAME_MIN_LENGTH = 2;
const CUSTOM_FEED_NAME_MAX_LENGTH = 50;

/**
 * Validate a custom feed name (ported from the legacy vanilla tab, removed in #1042).
 *
 * Only letters, underscores and hyphens are allowed (Cyrillic accepted for
 * Yandex/Zbozi/Heureka). Names containing `PARAM_` are accepted as-is,
 * matching the legacy behavior. Length must be 2–50.
 *
 * NOT a verbatim port — two deliberate corrections over the legacy rules:
 * `ёЁ` are allowed (they sit outside the `а-я`/`А-Я` ranges, so the legacy regex
 * rejected valid Cyrillic names), and the character-error message now mentions
 * underscores and hyphens, which the regex has always accepted. The legacy vanilla
 * validator still carries the old rules; the two only need to converge once this
 * app actually replaces that UI (#1037), since nothing mounts this store yet.
 *
 * @param input The custom feed name to validate.
 */
export const validateCustomFeedName = (input: string): FeedNameValidationResult => {
  const re = input.indexOf('PARAM_') >= 0 ? CUSTOM_FEED_NAME_ANY : CUSTOM_FEED_NAME_ALLOWED;
  const minLength = CUSTOM_FEED_NAME_MIN_LENGTH;
  const maxLength = CUSTOM_FEED_NAME_MAX_LENGTH;

  // Check for allowed characters
  if (!re.test(input)) {
    return {
      valid: false,
      message: __(
        'Sorry, when creating new custom fields only letters, underscores and hyphens are allowed (so no white spaces, numbers or any other special characters are allowed).',
        'woo-product-feed-pro'
      ),
    };
  }

  // Check for length of fieldname
  if (input.length < minLength) {
    return {
      valid: false,
      message: sprintf(
        /* translators: %d: minimum length required */
        __('Sorry, your custom field name needs to be at least %d letters long.', 'woo-product-feed-pro'),
        minLength
      ),
    };
  }

  if (input.length > maxLength) {
    return {
      valid: false,
      message: sprintf(
        /* translators: %d: maximum length allowed */
        __('Sorry, your custom field name cannot be over %d letters long.', 'woo-product-feed-pro'),
        maxLength
      ),
    };
  }

  return { valid: true };
};

/**
 * Validate a custom sub-attribute tag.
 *
 * Same rules as a custom feed name, plus an OPTIONAL namespace prefix: a nested
 * child generally has to sit in its parent's namespace to be valid for the
 * channel (Google's `<g:shipping>` holds `<g:country>`, not `<country>`), and
 * `Feed_Writer_XML::write_nested()` already resolves the namespace URI from the
 * tag's prefix. The registry's own defaults are all prefixed, so rejecting a
 * prefix here let seeded rows do something a user could not.
 *
 * The prefix and the local name are checked separately, then the whole tag is
 * checked against the writer's XML element-name pattern — that last step is what
 * stops this app accepting a tag the writer would silently skip.
 *
 * @param input The sub-attribute tag to validate.
 */
export const validateSubAttributeTag = (input: string): FeedNameValidationResult => {
  const separator = input.indexOf(':');

  if (separator >= 0) {
    const prefix = input.slice(0, separator);
    const local = input.slice(separator + 1);

    if (!XML_NAMESPACE_PREFIX.test(prefix)) {
      return {
        valid: false,
        message: __(
          'The namespace prefix before the colon must start with a letter and hold only letters, numbers, underscores, hyphens or dots (for example “g:”).',
          'woo-product-feed-pro'
        ),
      };
    }

    // One colon only: XML has no nested namespace prefixes.
    if (local.indexOf(':') >= 0) {
      return {
        valid: false,
        message: __('A sub-attribute name can hold only one colon, separating the namespace prefix from the name.', 'woo-product-feed-pro'),
      };
    }

    const localResult = validateCustomFeedName(local);
    if (!localResult.valid) {
      return localResult;
    }
  } else {
    const result = validateCustomFeedName(input);
    if (!result.valid) {
      return result;
    }
  }

  if (!XML_ELEMENT_NAME.test(input)) {
    return {
      valid: false,
      message: __(
        'This is not a valid XML element name, so it would be left out of the feed. Start it with a letter or underscore.',
        'woo-product-feed-pro'
      ),
    };
  }

  return { valid: true };
};

/**
 * Coerce an unknown API value to an object map.
 *
 * PHP serializes an empty associative array as `[]`, so only a real object map is
 * accepted — anything else (including an array) falls back to an empty map.
 */
const asObjectMap = <T>(value: unknown): T =>
  value && typeof value === 'object' && !Array.isArray(value) ? (value as T) : ({} as T);

/**
 * Create an empty top-level feed attribute row.
 */
const createEmptyAttribute = (): FeedAttribute => ({
  feed_name: '',
  source: 'field',
  source_value: '',
  prefix: '',
  suffix: '',
  is_custom: false,
});

/**
 * Create an empty custom sub-attribute row.
 */
const createEmptySubAttribute = (): SubAttribute => ({
  tag: '',
  // 'field' with no value is the UNSELECTED state, matching
  // createEmptyAttribute(): SourceControl resolves it to no selection, so the
  // row opens on "Select a source". Seeding 'static' made every new row open
  // pre-answered with a value type the user had not chosen.
  source: 'field',
  source_value: '',
  prefix: '',
  suffix: '',
});

/**
 * Sanity ceiling on a channel's declared `repeatable` limit.
 *
 * Mirrors `Repeatable_Attributes::MAX_REPEATS`. It is not a specification limit:
 * a channel declares its own real limit, and none currently exceeds 10.
 */
const MAX_REPEATS = 50;

/**
 * Clamp a catalog `repeatable` declaration to a usable maximum.
 *
 * Absent, unparseable or `1` all mean single-valued, which is every attribute
 * that does not opt in.
 */
const toRepeatMax = (declared: unknown): number => {
  const value = typeof declared === 'number' ? declared : Number.parseInt(String(declared ?? ''), 10);
  if (!Number.isFinite(value) || value <= 1) return 1;
  return Math.min(Math.floor(value), MAX_REPEATS);
};

/**
 * A repeat is a group of ONE row carrying no tag: the value belongs to the
 * parent element itself, not to a named child.
 *
 * Mirrors `Repeatable_Attributes::is_scalar_repeat_group()`. The two must agree
 * — a shape only one side recognises either saves and never emits, or emits and
 * cannot be edited back.
 */
const isRepeatGroup = (group: SubAttributeGroup | undefined): boolean =>
  Array.isArray(group) && group.length === 1 && (group[0]?.tag ?? '') === '';

/** The repeat groups of a row, or an empty list when the row is nested instead. */
const repeatGroupsOf = (attribute: FeedAttribute | undefined): SubAttributeGroup[] => {
  const groups = attribute?.sub_attributes ?? [];
  if (groups.length === 0) return [];
  return groups.every(isRepeatGroup) ? groups : [];
};

/**
 * Whether a row is configured as a NESTED parent rather than a repeating scalar.
 *
 * The two shapes share the `sub_attributes` key and cannot be mixed on one row,
 * so a repeatable tag that already carries tagged groups must keep showing the
 * sub-attribute panel — otherwise the stored groups become invisible and the
 * repeat panel offers an "Add value" action that its own guard then refuses.
 */
const hasNestedGroups = (attribute: FeedAttribute | undefined): boolean => {
  const groups = attribute?.sub_attributes ?? [];
  return groups.length > 0 && !groups.every(isRepeatGroup);
};

/**
 * Pinia store for the field mapping builder.
 * Manages the `feed_attributes` array, catalog metadata and validation state.
 */
export const useFieldMappingStore = defineStore('fieldMapping', () => {
  // State
  const loading = ref(false);
  const error = ref<string | null>(null);
  const feedId = ref<string | null>(null);
  const migrationRan = ref(false);

  // Core feed attributes state matching the `feed_attributes` schema
  const feedAttributes = ref<FeedAttribute[]>([]);

  // Metadata for dropdowns and sub-attribute pre-population
  const channelAttributes = ref<ChannelAttributeCatalog>({});
  const productFields = ref<AttributeCatalog>({});
  const subAttributeRegistry = ref<SubAttributeRegistry>({});

  // Server-computed map of Elite-gated catalog group label => upsell modal key.
  // Empty when Elite is active, so gating only ever runs on Pro-only sites.
  const eliteGatedAttrGroups = ref<Record<string, string>>({});

  // Validation state
  const validationErrors = ref<Record<string, string[]>>({});
  const showValidation = ref(false);

  // Row the UI should scroll to and focus, set when a row is created so the user
  // can see and name it immediately. Cleared once the row has claimed it.
  const focusRowKey = ref<string | null>(null);

  // Groups the user has expanded to reveal their unmapped optional attributes.
  const expandedGroups = ref<Set<string>>(new Set());

  // Table view state (search / group / filters / density / preview visibility)
  const search = ref('');
  const activeGroup = ref('all');
  const onlyRequired = ref(false);
  const density = ref<'comfy' | 'compact'>('comfy');
  const showPreview = ref(true);

  // Output preview state
  const previewLines = ref<PreviewLine[]>([]);
  const previewXml = ref('');
  const previewProduct = ref<FieldMappingPreviewResponse['product'] | null>(null);
  const previewLoading = ref(false);
  const previewError = ref<string | null>(null);

  /*
   * Why the preview is unavailable for this feed, when it is. Kept apart from
   * `previewError`: nothing failed, the feature simply does not cover this
   * channel, and rendering that as an error would read as a bug.
   */
  const previewUnsupported = ref<string | null>(null);

  // The product the preview resolves against. Null means "let the server pick",
  // which is the newest published product.
  const previewProductId = ref<number | null>(null);
  const productOptions = ref<PreviewProductOption[]>([]);
  const productSearchLoading = ref(false);

  // Monotonic token for searchProducts(); see the guard there.
  let productSearchId = 0;

  // Titles of pages and posts referenced by page_url / post_url rows, keyed
  // `page:123` / `post:456`. The lists are no longer shipped with the page, so
  // a stored ID needs its label looked up once and remembered.
  const objectTitles = ref<Record<string, string>>({});

  // Variations of the product being previewed, when it is a variable product.
  // Variations are their own feed items, so they are previewable in their own
  // right rather than only through their parent.
  const variationOptions = ref<PreviewProductOption[]>([]);
  /** True when the parent has more variations than the route will list. */
  const variationsTruncated = ref(false);

  // Getters
  const hasAttributes = computed(() => feedAttributes.value.length > 0);

  const isFormValid = computed(() => {
    // Only validate if showValidation is true
    if (!showValidation.value) {
      return true; // Consider form valid if validation hasn't been triggered yet
    }
    return Object.keys(validationErrors.value).length === 0;
  });

  const getFieldErrors = computed(() => (fieldId: string) => {
    // Only show errors if validation has been triggered
    if (!showValidation.value) {
      return [];
    }
    return validationErrors.value[fieldId] || [];
  });

  // Exact inverse of isFormValid, which already accounts for showValidation.
  const hasValidationErrors = computed(() => !isFormValid.value);

  // Actions

  /**
   * Coerce an unknown API value to a string, accepting numbers (e.g. page IDs).
   */
  const toStringValue = (value: unknown, fallback = ''): string => {
    if (typeof value === 'string') {
      return value;
    }
    if (typeof value === 'number') {
      return String(value);
    }
    return fallback;
  };

  /**
   * Normalize an unknown API row into a sub-attribute with the full schema.
   */
  const normalizeSubAttribute = (rawSub: unknown): SubAttribute => {
    const sub = (rawSub && typeof rawSub === 'object' ? rawSub : {}) as Record<string, unknown>;
    const empty = createEmptySubAttribute();

    return {
      tag: toStringValue(sub.tag, empty.tag),
      source: isSubAttributeSource(sub.source) ? sub.source : empty.source,
      source_value: toStringValue(sub.source_value, empty.source_value),
      prefix: toStringValue(sub.prefix, empty.prefix),
      suffix: toStringValue(sub.suffix, empty.suffix),
    };
  };

  /**
   * Normalize loaded feed attributes to ensure each row carries the full schema.
   */
  const normalizeFeedAttributes = (rows: unknown): FeedAttribute[] => {
    if (!Array.isArray(rows)) {
      return [];
    }

    return rows.map((rawRow): FeedAttribute => {
      const row = (rawRow && typeof rawRow === 'object' ? rawRow : {}) as Record<string, unknown>;
      const empty = createEmptyAttribute();

      const normalized: FeedAttribute = {
        feed_name: toStringValue(row.feed_name, empty.feed_name),
        source: isAttributeSource(row.source) ? row.source : empty.source,
        source_value: toStringValue(row.source_value, empty.source_value),
        prefix: toStringValue(row.prefix, empty.prefix),
        suffix: toStringValue(row.suffix, empty.suffix),
        is_custom: !!row.is_custom,
      };

      if (row.zones_auto !== undefined) {
        normalized.zones_auto = !!row.zones_auto;
      }

      if (Array.isArray(row.sub_attributes)) {
        normalized.sub_attributes = row.sub_attributes.map((group): SubAttributeGroup =>
          Array.isArray(group) ? group.map(normalizeSubAttribute) : []
        );
      }

      return normalized;
    });
  };

  /**
   * Load field mappings data from the REST API.
   */
  const loadFieldMappings = async (id: string) => {
    try {
      loading.value = true;
      error.value = null;
      feedId.value = id;

      // Use 'new' for new feeds that don't have an ID yet
      const apiId = id === 'new' || !id ? 'new' : id;

      const response = await api().get(`adtribes/v1/field-mappings/${encodeURIComponent(apiId)}`);
      const data = response.data as FieldMappingsApiResponse;

      feedAttributes.value = normalizeFeedAttributes(data.feed_attributes);

      channelAttributes.value = asObjectMap<ChannelAttributeCatalog>(data.channel_attributes);

      // Needs the catalog, so it cannot live in normalizeFeedAttributes().
      foldLegacyRepeatRows();
      productFields.value = asObjectMap<AttributeCatalog>(data.product_fields);
      subAttributeRegistry.value = asObjectMap<SubAttributeRegistry>(data.sub_attribute_registry);
      eliteGatedAttrGroups.value = asObjectMap<Record<string, string>>(data.eliteGatedAttrGroups);

      if (data.migration_ran) {
        migrationRan.value = true;
      }
    } catch (err) {
      // Surface the failure through the reactive error state; the UI renders it.
      error.value =
        err instanceof Error ? err.message : __('Failed to load field mappings', 'woo-product-feed-pro');
    } finally {
      loading.value = false;
    }
  };

  /**
   * Pre-populate the required channel attribute rows when the feed has no
   * mapping yet.
   *
   * Mirrors the legacy tab: every catalog definition with `format ===
   * 'required'` becomes a row, its `woo_suggest` value mapped onto the new
   * source enum (`static_value:X` / `page:X` / `post:X` markers included) and
   * `{{CURRENCY}}` placeholders in prefix/suffix replaced with the store
   * currency. Gate tags join them: `g:gtin` is what relaxes `g:mpn`, so it has
   * to be as easy to reach as `g:mpn` itself.
   *
   * @param currency The store currency code (e.g. `AUD`).
   */
  const prepopulateRequiredRows = (currency: string) => {
    if (feedAttributes.value.length > 0) {
      return;
    }

    const rows: FeedAttribute[] = [];
    const gates = collectGateTags(channelAttributes.value);

    for (const groupName in channelAttributes.value) {
      const group = channelAttributes.value[groupName];

      for (const label in group) {
        const definition = group[label];
        if (!definition || !definition.feed_name) {
          continue;
        }
        if (definition.format !== 'required' && !gates.has(definition.feed_name)) {
          continue;
        }

        const suggest = typeof definition.woo_suggest === 'string' ? definition.woo_suggest : '';
        const { source, sourceValue } = parseWooSuggest(suggest);

        rows.push({
          feed_name: definition.feed_name,
          source,
          source_value: sourceValue,
          prefix: replaceCurrency(definition.prefix, currency),
          suffix: replaceCurrency(definition.suffix, currency),
          is_custom: false,
        });
      }
    }

    feedAttributes.value = rows;
  };

  /**
   * Add a new attribute row.
   *
   * Returns the new row's index so the caller can reveal it.
   */
  const addAttribute = (attribute: Partial<FeedAttribute> = {}) => {
    feedAttributes.value.push({
      ...createEmptyAttribute(),
      ...attribute,
    });

    // Rows with no catalog entry are keyed by their index in `specRows`.
    focusRowKey.value = `extra:${feedAttributes.value.length - 1}`;

    // Clear validation when user adds new rows
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Remove an attribute row.
   */
  const removeAttribute = (index: number) => {
    if (index < 0 || index >= feedAttributes.value.length) return;

    feedAttributes.value.splice(index, 1);

    // Clear validation when user removes rows
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Update an attribute row's properties.
   */
  const updateAttribute = (index: number, updates: Partial<FeedAttribute>) => {
    const attribute = feedAttributes.value[index];
    if (!attribute) return;

    Object.assign(attribute, updates);

    // Clear validation errors for this row when user starts editing again
    if (showValidation.value && validationErrors.value[`attribute-${index}`]) {
      delete validationErrors.value[`attribute-${index}`];
    }
  };

  /**
   * Change an attribute row's output tag.
   *
   * Sub-attribute groups belong to the tag they were configured for, so the
   * old configuration (and its `zones_auto` flag) is dropped before the new
   * tag's registry defaults are applied. Without the reset, switching a parent
   * from `g:shipping` to a flat tag would keep serializing the stale nested
   * groups.
   *
   * @param index    The attribute row index.
   * @param feedName The new output tag.
   */
  const changeAttributeFeedName = (index: number, feedName: string) => {
    const attribute = feedAttributes.value[index];
    if (!attribute) return;

    if (attribute.feed_name === feedName) return;

    attribute.feed_name = feedName;
    delete attribute.sub_attributes;
    delete attribute.zones_auto;

    applyRegistryDefaults(index);

    // Clear validation errors for this row when user starts editing again.
    if (showValidation.value && validationErrors.value[`attribute-${index}`]) {
      delete validationErrors.value[`attribute-${index}`];
    }
  };

  /**
   * The registry tags for a parent output tag, as a lookup set.
   *
   * Components use it to tell registry-seeded sub-attribute rows (fixed tag,
   * rendered as a label) apart from custom rows (free-text tag, validated).
   *
   * @param feedName The parent output tag.
   */
  const getRegistryTags = (feedName: string): Set<string> => {
    const entries = subAttributeRegistry.value[feedName];
    return new Set((entries ?? []).map((entry) => entry.tag));
  };

  /**
   * Repeat limits for the loaded channel, keyed by output tag.
   */
  const repeatLimits = computed<Record<string, number>>(() => {
    const limits: Record<string, number> = {};

    for (const groupName in channelAttributes.value) {
      const definitions = channelAttributes.value[groupName];
      for (const label in definitions) {
        const definition = definitions[label];
        if (!definition?.feed_name) continue;
        const max = toRepeatMax(definition.repeatable);
        if (max > 1) limits[String(definition.feed_name)] = max;
      }
    }

    return limits;
  });

  /**
   * Fold a legacy feed's duplicate rows into repeats of one row.
   *
   * Before #1133 the only way to emit a tag twice was to map it twice, either
   * through the channel's numbered catalog slots (`included_destination_1` …
   * `_5`) or by hand. Those rows share one `feed_name`, so `specRows` binds the
   * first to the catalog slot and pushes the rest into "Custom fields", where
   * they read as unrelated stray rows.
   *
   * Folding them makes the table show what the feed actually emits: one
   * attribute, N values. The generated output is unchanged either way — the PHP
   * side expands repeats into the same `_N` payload keys the duplicate rows
   * already produced — so this is a display normalisation that only reaches
   * storage if the user goes on to save.
   *
   * Only DECLARED repeatable tags fold. Two rows sharing a tag that the channel
   * says is single-valued stay as they are: that is a mistake to show, not to
   * tidy away.
   */
  const foldLegacyRepeatRows = () => {
    const limits = repeatLimits.value;
    if (Object.keys(limits).length === 0) return;

    const kept: FeedAttribute[] = [];
    const firstByFeedName = new Map<string, FeedAttribute>();

    feedAttributes.value.forEach((attribute) => {
      const max = limits[attribute.feed_name];
      const first = max ? firstByFeedName.get(attribute.feed_name) : undefined;

      if (!max) {
        kept.push(attribute);
        return;
      }

      if (!first) {
        firstByFeedName.set(attribute.feed_name, attribute);
        kept.push(attribute);
        return;
      }

      // A duplicate row of a repeatable tag becomes a repeat of the first row.
      // Its own nested groups, if it somehow had any, are dropped with it: a
      // scalar repeat has no children, and keeping them would leave a shape
      // neither side reads.
      const groups = first.sub_attributes ?? [];

      // Never mix the two shapes on one row. If the first row is configured as a
      // NESTED parent, appending a repeat would make `sub_attributes` half one
      // thing and half the other, which both readers then decline to interpret.
      // Leave the duplicate visible instead of quietly corrupting the mapping.
      if (hasNestedGroups(first)) {
        kept.push(attribute);
        return;
      }

      if (groups.length + 1 >= max) {
        // Past the channel's limit the extra rows are left alone rather than
        // silently discarded — the user can see and remove them.
        kept.push(attribute);
        return;
      }

      groups.push([
        {
          tag: '',
          source: attribute.source,
          source_value: attribute.source_value,
          prefix: attribute.prefix,
          suffix: attribute.suffix,
        },
      ]);
      first.sub_attributes = groups;
    });

    feedAttributes.value = kept;
  };

  /**
   * The configured repeats of an attribute row — values two and up.
   *
   * Value one is the row's own `source`/`source_value`, exactly as on any other
   * row, which is what lets a single-valued feed need no migration.
   */
  const getRepeats = (index: number): SubAttributeGroup[] => repeatGroupsOf(feedAttributes.value[index]);

  /**
   * Whether the row is a nested parent, so the repeat panel must stand aside.
   */
  const isNestedParent = (index: number): boolean => hasNestedGroups(feedAttributes.value[index]);

  /**
   * Whether another repeat still fits under the channel's declared limit.
   *
   * A nested parent can never take one — the shapes cannot be mixed — and that
   * has to be answered here rather than only inside `addRepeat()`, or the panel
   * renders an "Add value" button that does nothing when clicked.
   */
  const canAddRepeat = (index: number, repeatMax: number): boolean =>
    repeatMax > 1 && !isNestedParent(index) && getRepeats(index).length + 1 < repeatMax;

  /**
   * Add an empty repeat to an attribute row.
   */
  const addRepeat = (index: number, repeatMax: number) => {
    const attribute = feedAttributes.value[index];
    if (!attribute || !canAddRepeat(index, repeatMax)) return;

    attribute.sub_attributes = [...(attribute.sub_attributes ?? []), [createEmptySubAttribute()]];

    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Update one repeat's source or affixes.
   */
  const updateRepeat = (index: number, repeatIndex: number, updates: Omit<Partial<SubAttribute>, 'tag'>) => {
    const row = getRepeats(index)[repeatIndex]?.[0];
    if (!row) return;

    // `tag` is what marks the row as a repeat, so the signature does not accept it.
    Object.assign(row, updates);
  };

  /**
   * Remove one repeat from an attribute row.
   */
  const removeRepeat = (index: number, repeatIndex: number) => {
    const attribute = feedAttributes.value[index];
    const repeats = getRepeats(index);
    if (!attribute || repeatIndex < 0 || repeatIndex >= repeats.length) return;

    repeats.splice(repeatIndex, 1);

    if (repeats.length === 0) {
      delete attribute.sub_attributes;
    }

    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Swap a repeat with its neighbour. Repeat order is output order.
   */
  const moveRepeat = (index: number, repeatIndex: number, offset: number) => {
    const attribute = feedAttributes.value[index];
    const repeats = getRepeats(index);
    const next = repeatIndex + offset;
    if (!attribute || next < 0 || next >= repeats.length) return;

    const reordered = [...repeats];
    [reordered[repeatIndex], reordered[next]] = [reordered[next], reordered[repeatIndex]];
    attribute.sub_attributes = reordered;
  };

  /**
   * Apply the registry default sub-attributes to an attribute row.
   *
   * Looks up the row's feed_name in the sub-attribute registry and, when the
   * row has no sub-attributes configured yet, seeds one template group from
   * the registry defaults. Parents with zone-resolved defaults (g:shipping)
   * are flagged `zones_auto` so the template group is expanded per
   * WooCommerce shipping zone at generation time.
   */
  const applyRegistryDefaults = (index: number) => {
    const attribute = feedAttributes.value[index];
    if (!attribute) return;

    const defaults = subAttributeRegistry.value[attribute.feed_name];
    if (!defaults || defaults.length === 0) return;

    // Don't overwrite an existing sub-attribute configuration.
    if (attribute.sub_attributes && attribute.sub_attributes.length > 0) return;

    const group: SubAttributeGroup = defaults.map((entry) => ({
      tag: entry.tag,
      source: entry.default_source,
      source_value: entry.default_value || '',
      prefix: '',
      suffix: '',
    }));

    attribute.sub_attributes = [group];

    // Zone-resolved parents (g:shipping) expand the single template group
    // per WooCommerce shipping zone at generation time.
    if (defaults.some((entry) => entry.default_source === 'zone')) {
      attribute.zones_auto = true;
    }
  };

  /**
   * Add a custom sub-attribute row to a group.
   */
  const addSubAttribute = (parentIndex: number, groupIndex: number) => {
    const attribute = feedAttributes.value[parentIndex];
    if (!attribute) return;

    if (!attribute.sub_attributes) {
      attribute.sub_attributes = [[]];
    }

    const group = attribute.sub_attributes[groupIndex];
    if (!group) return;

    group.push(createEmptySubAttribute());

    // Errors are keyed by row indices, so any structural change invalidates
    // them — same behavior as the top-level add/remove mutations.
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Update a sub-attribute row's properties.
   */
  const updateSubAttribute = (
    parentIndex: number,
    groupIndex: number,
    subIndex: number,
    updates: Partial<SubAttribute>
  ) => {
    const sub = feedAttributes.value[parentIndex]?.sub_attributes?.[groupIndex]?.[subIndex];
    if (!sub) return;

    Object.assign(sub, updates);

    // Clear validation errors for this sub row when user starts editing again.
    const errorKey = `attribute-${parentIndex}-sub-${groupIndex}-${subIndex}`;
    if (showValidation.value && validationErrors.value[errorKey]) {
      delete validationErrors.value[errorKey];
    }
  };

  /**
   * Replace a parent's sub-attribute groups (drag-reorder handoff).
   *
   * Group order is output order: the writer emits one parent element per
   * group, in array order.
   */
  const setSubAttributeGroups = (parentIndex: number, groups: SubAttributeGroup[]) => {
    const attribute = feedAttributes.value[parentIndex];
    if (!attribute) return;

    attribute.sub_attributes = groups;

    // Errors are keyed by group/row indices, which a reorder rewrites.
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Remove a sub-attribute row from a group.
   */
  const removeSubAttribute = (parentIndex: number, groupIndex: number, subIndex: number) => {
    const group = feedAttributes.value[parentIndex]?.sub_attributes?.[groupIndex];
    if (!group) return;

    if (subIndex < 0 || subIndex >= group.length) return;

    group.splice(subIndex, 1);

    // Errors are keyed by row indices, which this shift invalidates. Without
    // the clear, a removed invalid row leaves a stale key that pins the save
    // button disabled (validation only reruns on submit).
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Add a repeating sub-attribute group to a parent attribute.
   *
   * The new group mirrors the shape of the parent's first group (same tags
   * and sources, empty values) so repeating parents like g:tax keep a
   * consistent structure across groups.
   */
  const addSubAttributeGroup = (parentIndex: number) => {
    const attribute = feedAttributes.value[parentIndex];
    if (!attribute) return;

    if (!attribute.sub_attributes) {
      attribute.sub_attributes = [];
    }

    const template = attribute.sub_attributes[0];
    const newGroup: SubAttributeGroup = template
      ? template.map((sub) => ({
          ...sub,
          source_value: '',
        }))
      : [];

    attribute.sub_attributes.push(newGroup);

    // Errors are keyed by group/row indices — same clear as add/remove rows.
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Remove a repeating sub-attribute group from a parent attribute.
   */
  const removeSubAttributeGroup = (parentIndex: number, groupIndex: number) => {
    const attribute = feedAttributes.value[parentIndex];
    if (!attribute || !attribute.sub_attributes) return;

    if (groupIndex < 0 || groupIndex >= attribute.sub_attributes.length) return;

    attribute.sub_attributes.splice(groupIndex, 1);

    // Drop the key entirely when no groups remain to keep the payload clean.
    if (attribute.sub_attributes.length === 0) {
      delete attribute.sub_attributes;
    }

    // Errors are keyed by group/row indices, which this shift invalidates.
    if (showValidation.value) {
      clearValidationErrors();
    }
  };

  /**
   * Validate a single attribute row. Returns the row's error messages.
   */
  const validateAttribute = (attribute: FeedAttribute): string[] => {
    const errors: string[] = [];

    // Custom (own mapping) feed names must pass the custom field name rules.
    if (attribute.is_custom) {
      const result = validateCustomFeedName(attribute.feed_name);
      if (!result.valid && result.message) {
        errors.push(result.message);
      }
    }

    /*
     * A page/post source with nothing selected is an unfinished mapping. It
     * cannot be dropped silently the way a cleared field row is - the user
     * deliberately chose the source - but saving it would put an empty element
     * in the feed, so block the save until a target is picked.
     */
    if (
      ( 'page_url' === attribute.source || 'post_url' === attribute.source ) &&
      !attribute.source_value
    ) {
      errors.push(
        'page_url' === attribute.source
          ? __('Select a page for this attribute, or choose a different source.', 'woo-product-feed-pro')
          : __('Select a post for this attribute, or choose a different source.', 'woo-product-feed-pro')
      );
    }

    /*
     * A static value outside the channel's closed set is an item-level
     * disapproval in Merchant Center, and the feed generates without complaint,
     * so the plugin is the only place it can surface. The picker cannot produce
     * one; a feed saved before the picker existed can, and swapping that value
     * silently would edit the store's data behind its back. Flag it instead and
     * let the store choose the replacement.
     */
    if ('static' === attribute.source && attribute.source_value) {
      const allowed = allowedValuesByTag.value[attribute.feed_name];

      /*
       * Compared case-insensitively. Google accepts these values in any case,
       * and the plugin's own feed analysis already does too (`class-get-products.php`
       * allows `new` and `New` alike), so a case-sensitive check here would flag a
       * legacy static `New` and block a save that produced a valid feed. The picker
       * still emits the channel's canonical case; only this check relaxes.
       */
      const target = attribute.source_value.toLowerCase();

      if (allowed?.length && !allowed.some((value) => value.toLowerCase() === target)) {
        errors.push(
          sprintf(
            /* translators: 1: the value currently mapped, 2: comma-separated list of the values the channel accepts. */
            __('%1$s is not a value this attribute accepts. Choose one of: %2$s.', 'woo-product-feed-pro'),
            attribute.source_value,
            allowed.join(', ')
          )
        );
      }
    }

    return errors;
  };

  /**
   * Validate all attribute rows and update validation errors for UI display.
   */
  const updateValidationErrors = () => {
    showValidation.value = true;

    const allErrors: Record<string, string[]> = {};

    feedAttributes.value.forEach((attribute, index) => {
      const rowErrors = validateAttribute(attribute);
      if (rowErrors.length > 0) {
        allErrors[`attribute-${index}`] = rowErrors;
      }

      // Custom sub-attribute tags follow the custom feed name rules plus an
      // optional namespace prefix. Registry-seeded rows (fixed tag) and
      // zone-locked rows are exempt.
      const registryTags = getRegistryTags(attribute.feed_name);
      const isRepeatable = !!repeatLimits.value[attribute.feed_name];

      attribute.sub_attributes?.forEach((group, groupIndex) => {
        // A scalar repeat carries no tag by definition, so there is no name to
        // validate — the tag validator would reject the empty string.
        //
        // Only on an attribute the channel DECLARES repeatable, though. A blank tag
        // anywhere else is a half-finished nested sub-attribute that resolves to
        // nothing, and the validator is what tells the user so.
        if (isRepeatable && isRepeatGroup(group)) {
          return;
        }

        group.forEach((sub, subIndex) => {
          if (sub.source === 'zone' || registryTags.has(sub.tag)) {
            return;
          }

          const result = validateSubAttributeTag(sub.tag);
          if (!result.valid && result.message) {
            allErrors[`attribute-${index}-sub-${groupIndex}-${subIndex}`] = [result.message];
          }
        });
      });
    });

    validationErrors.value = allErrors;
  };

  /**
   * Clear validation errors.
   */
  const clearValidationErrors = () => {
    validationErrors.value = {};
    showValidation.value = false;
  };

  // ---------------------------------------------------------------------------
  // Specification view
  //
  // The table shows the channel's WHOLE catalog (133 attributes for Google), not
  // just the rows the user mapped. That view is composed here and never stored:
  // `feedAttributes` keeps holding mapped rows only, so the saved payload, the
  // writers and the generated feed are untouched by rows the user only looked at.
  // ---------------------------------------------------------------------------

  /**
   * Flatten the channel catalog into an ordered lookup, keyed by output tag.
   */
  const catalogEntries = computed(() => {
    const entries: Array<{
      feedName: string;
      label: string;
      group: string;
      format: RequirementLevel;
      requiredUnless: string;
      requiredWhen: ChannelAttributeDefinition['required_when'];
      wooSuggest: string;
      allowedValues: string[];
      repeatMax: number;
      choices: string[];
    }> = [];

    for (const groupName in channelAttributes.value) {
      const definitions = channelAttributes.value[groupName];

      for (const attributeLabel in definitions) {
        const definition = definitions[attributeLabel];
        if (!definition || !definition.feed_name) continue;

        const format = definition.format === 'required' || definition.format === 'recommended' ? definition.format : 'optional';

        entries.push({
          feedName: String(definition.feed_name),
          label: String(attributeLabel),
          group: String(groupName),
          format,
          requiredUnless: typeof definition.required_unless === 'string' ? definition.required_unless : '',
          requiredWhen:
            definition.required_when && typeof definition.required_when.feed_name === 'string'
              ? definition.required_when
              : undefined,
          wooSuggest: typeof definition.woo_suggest === 'string' ? definition.woo_suggest : '',
          allowedValues: Array.isArray(definition.allowed_values) ? definition.allowed_values.map(String) : [],
          repeatMax: toRepeatMax(definition.repeatable),
          choices: Array.isArray(definition.choices) ? definition.choices.map(String) : [],
        });
      }
    }

    return entries;
  });

  /**
   * Output tag => the channel's closed value set for it.
   *
   * Derived from `catalogEntries` so "what counts as a cataloged, feed_name-bearing
   * attribute" is decided in exactly one place. Not from `specRows`, which mixes in
   * `feedAttributes`: `validateAttribute` runs over those rows, so it must not
   * depend on them. It lives here, below the flattening it reads, rather than beside
   * its caller.
   */
  const allowedValuesByTag = computed(() => {
    const map: Record<string, string[]> = {};

    for (const entry of catalogEntries.value) {
      if (entry.allowedValues.length) {
        map[entry.feedName] = entry.allowedValues;
      }
    }

    return map;
  });

  /**
   * Whether a mapping entry resolves to something: a source value, or
   * sub-attributes carrying the value instead.
   */
  const hasResolvedValue = (attribute: FeedAttribute | undefined): boolean =>
    !!attribute && (!!attribute.source_value || !!attribute.sub_attributes?.length);

  /**
   * Every table row: the full catalog, plus any mapped row the catalog does not
   * describe (custom fields, and rows left over from a channel change).
   */
  const specRows = computed<SpecRow[]>(() => {
    // First mapped occurrence of each output tag wins the catalog slot; later
    // duplicates fall through to the extra rows below, keeping their own row.
    const claimed = new Set<number>();
    const byFeedName = new Map<string, number>();

    feedAttributes.value.forEach((attribute, index) => {
      if (attribute.feed_name && !byFeedName.has(attribute.feed_name)) {
        byFeedName.set(attribute.feed_name, index);
      }
    });

    /**
     * Resolve a catalog entry's requirement level against the current mapping.
     *
     * A conditionally required attribute names the output tag that relaxes it
     * (`g:mpn` names `g:gtin`). While that tag has no resolved value the
     * attribute stays required; once it does, the attribute drops to
     * recommended, so the badge, the counts and the "still unmapped" message
     * all follow the channel's real rule.
     */
    const effectiveFormat = (format: RequirementLevel, requiredUnless: string): RequirementLevel => {
      if (format !== 'required' || !requiredUnless) return format;

      const gateIndex = byFeedName.get(requiredUnless);
      const gateMapped = gateIndex !== undefined && hasResolvedValue(feedAttributes.value[gateIndex]);

      return gateMapped ? 'recommended' : 'required';
    };

    /**
     * Whether a `required_when` rule is currently armed.
     *
     * The rule names a tag and the one static value that makes this attribute
     * required (`g:availability` set to `preorder`). Only a static mapping can
     * arm it: a value read from a product field varies per product and is not
     * known until the feed is generated, so the editor treats it as unarmed and
     * leaves the attribute optional.
     */
    /** The gate tag's static text, or null while it is not mapped to a static value. */
    const gateStaticValue = (requiredWhen: ChannelAttributeDefinition['required_when']): string | null => {
      if (!requiredWhen?.feed_name) return null;

      const gateIndex = byFeedName.get(requiredWhen.feed_name);
      if (gateIndex === undefined) return null;

      const gate = feedAttributes.value[gateIndex];
      if (!gate || gate.source !== 'static') return null;

      return gate.source_value.trim().toLowerCase();
    };

    const isRequiredWhenArmed = (requiredWhen: ChannelAttributeDefinition['required_when']): boolean => {
      if (!requiredWhen?.static) return false;

      return gateStaticValue(requiredWhen) === requiredWhen.static.trim().toLowerCase();
    };

    const rows: SpecRow[] = catalogEntries.value.map((entry) => {
      const attrIndex = byFeedName.has(entry.feedName) ? (byFeedName.get(entry.feedName) as number) : null;
      if (attrIndex !== null) claimed.add(attrIndex);

      const armed = isRequiredWhenArmed(entry.requiredWhen);

      /*
       * The notice explains the rule, so it is shown to whoever is in a position
       * to trip it: anyone typing a static value into the gate tag, not only the
       * one who already typed the value that arms it. Choosing a static
       * availability is exactly the moment "preorder needs a date" is worth
       * knowing. It stays silent for the default field mapping, which every
       * Google feed has — an always-on notice would be noise on all of them.
       */
      const explain = armed || gateStaticValue(entry.requiredWhen) !== null;

      return {
        key: `spec:${entry.feedName}`,
        feedName: entry.feedName,
        label: entry.label,
        group: entry.group,
        format: armed ? 'required' : effectiveFormat(entry.format, entry.requiredUnless),
        attrIndex,
        isCustom: false,
        wooSuggest: entry.wooSuggest,
        allowedValues: entry.allowedValues,
        repeatMax: entry.repeatMax,
        choices: entry.choices,
        requirementNotice: explain ? entry.requiredWhen?.notice ?? '' : '',
      };
    });

    // Mapped rows with no catalog entry: custom fields and anything the channel
    // no longer describes. They are always "mapped", so they always serialize.
    feedAttributes.value.forEach((attribute, index) => {
      if (claimed.has(index)) return;

      rows.push({
        key: `extra:${index}`,
        feedName: attribute.feed_name,
        label: attribute.feed_name || __('(unnamed field)', 'woo-product-feed-pro'),
        group: CUSTOM_GROUP_LABEL,
        format: 'optional',
        attrIndex: index,
        isCustom: true,
        wooSuggest: '',
        allowedValues: [],
        repeatMax: 1,
        choices: [],
        requirementNotice: '',
      });
    });

    return rows;
  });

  /**
   * A row counts as mapped when it has a feedAttributes entry that resolves to
   * something: a source value, or sub-attributes carrying the value instead.
   */
  const isRowMapped = (row: SpecRow): boolean => {
    if (row.attrIndex === null) return false;
    return hasResolvedValue(feedAttributes.value[row.attrIndex]);
  };

  /**
   * Per-group and overall counts driving the navigator and progress card.
   */
  const groupSummary = computed(() => {
    const summary: Record<string, { total: number; mapped: number; required: number; missing: number }> = {};

    specRows.value.forEach((row) => {
      if (!summary[row.group]) {
        summary[row.group] = { total: 0, mapped: 0, required: 0, missing: 0 };
      }
      const bucket = summary[row.group];
      const mapped = isRowMapped(row);

      bucket.total += 1;
      if (mapped) bucket.mapped += 1;
      if (row.format === 'required') {
        bucket.required += 1;
        if (!mapped) bucket.missing += 1;
      }
    });

    return summary;
  });

  const overallSummary = computed(() => {
    const totals = { total: 0, mapped: 0, required: 0, missing: 0 };

    specRows.value.forEach((row) => {
      const mapped = isRowMapped(row);
      totals.total += 1;
      if (mapped) totals.mapped += 1;
      if (row.format === 'required') {
        totals.required += 1;
        if (!mapped) totals.missing += 1;
      }
    });

    return totals;
  });

  /**
   * Rows after the search, group and required-only filters.
   */
  const visibleRows = computed(() => {
    const term = search.value.trim().toLowerCase();

    return specRows.value.filter((row) => {
      if (activeGroup.value !== 'all' && row.group !== activeGroup.value) return false;
      if (onlyRequired.value && row.format !== 'required') return false;
      if (term && !`${row.label} ${row.feedName}`.toLowerCase().includes(term)) return false;
      return true;
    });
  });

  /**
   * A row is "in use" when it exists in the mapping, or the channel asks for it.
   *
   * Deliberately looser than `isRowMapped`: presence in `feedAttributes` is what
   * matters, not whether a value has been chosen yet. Two things break if this
   * asks for a resolved value:
   *
   * - A freshly added custom field has no name or source yet, so it would be
   *   filed under the group's collapsed optional tail the moment it was created,
   *   leaving the user unsure whether the click worked at all.
   * - A row that is blocking the save - a page/post source with nothing picked -
   *   would hide behind the same tail, so its inline error would be unreachable
   *   while the Save button stayed disabled.
   * - A gate tag would hide while the row it relaxes stayed pinned as Required,
   *   so the user would read "map MPN" with the better answer - map GTIN -
   *   folded away out of sight.
   * - A row carrying a requirement notice would explain itself to nobody. The
   *   notice is raised because the current mapping is about to make the row
   *   matter, which is the definition of in use.
   *
   * `recommended` earns its own clause for a separate reason, one the
   * resolved-value question does not touch: a badge carried from inside the
   * collapsed tail is the same silence as no badge at all, so a recommendation
   * gets a place on screen just as `required` does. The tail keeps the rest of
   * the catalog - the attributes the channel has no opinion about.
   */
  const gateTags = computed(() => collectGateTags(channelAttributes.value));

  const isRowInUse = (row: SpecRow): boolean =>
    row.attrIndex !== null ||
    row.format === 'required' ||
    row.format === 'recommended' ||
    !!row.requirementNotice ||
    gateTags.value.has(row.feedName);

  /**
   * Whether progressive disclosure applies.
   *
   * Only in the unfiltered "All fields" view. Picking a group, searching, or
   * ticking "required only" are all explicit requests to see a specific set, so
   * those views show every row that matches instead of hiding any.
   */
  const isBrowsingAll = computed(
    () => activeGroup.value === 'all' && '' === search.value.trim() && !onlyRequired.value
  );

  /**
   * Visible rows bucketed by catalog group, with the optional tail collapsed.
   *
   * The channel catalog is large — 133 attributes for Google — so rendering all
   * of it at once buries the mapping the user came to edit. Each group shows the
   * rows in use and reports how many optional attributes it is holding back, so
   * the catalog stays discoverable without the page paying for it.
   */
  const groupedVisibleRows = computed(() => {
    const buckets = new Map<string, SpecRow[]>();

    visibleRows.value.forEach((row) => {
      if (!buckets.has(row.group)) buckets.set(row.group, []);
      (buckets.get(row.group) as SpecRow[]).push(row);
    });

    return Array.from(buckets.entries()).map(([label, rows]) => {
      if (!isBrowsingAll.value || expandedGroups.value.has(label)) {
        return { label, rows, hiddenCount: 0, expanded: true };
      }

      const shown = rows.filter(isRowInUse);

      return { label, rows: shown, hiddenCount: rows.length - shown.length, expanded: false };
    });
  });

  /** The rows actually on screen, after grouping and disclosure. */
  const renderedRows = computed(() => groupedVisibleRows.value.flatMap((group) => group.rows));

  /**
   * Reveal or re-collapse one group's optional attributes.
   */
  const toggleGroupExpanded = (group: string) => {
    const next = new Set(expandedGroups.value);
    if (next.has(group)) {
      next.delete(group);
    } else {
      next.add(group);
    }
    expandedGroups.value = next;
  };

  /**
   * Put the channel's whole catalog on screen: clear every filter and open each
   * group's collapsed tail.
   *
   * This is what the legacy tab's `+ Add field mapping` button now does. That
   * table listed only mapped rows, so the button appended a row carrying a
   * channel-attribute dropdown. Here every attribute already HAS a row and a
   * source picker, so the only thing between the user and an optional attribute
   * is the group disclosure — which is what this opens.
   */
  const browseAllAttributes = () => {
    search.value = '';
    activeGroup.value = 'all';
    onlyRequired.value = false;
    expandedGroups.value = new Set(specRows.value.map((row) => row.group));
  };

  /**
   * Map a currently-inert row by giving it a feedAttributes entry.
   *
   * Called the moment the user picks a source for an unmapped spec row. Returns
   * the new index so the caller can keep editing the same row.
   */
  const mapSpecRow = (row: SpecRow, updates: Partial<FeedAttribute> = {}): number => {
    if (row.attrIndex !== null) return row.attrIndex;

    feedAttributes.value.push({
      ...createEmptyAttribute(),
      feed_name: row.feedName,
      is_custom: row.isCustom,
      ...updates,
    });

    return feedAttributes.value.length - 1;
  };

  /**
   * How many rows auto-map would fill: unmapped, and suggested by the catalog.
   */
  const autoMapCandidates = computed(
    () => specRows.value.filter((row) => !isRowMapped(row) && !!row.wooSuggest).length
  );

  /**
   * Fill every unmapped row that the catalog suggests a source for.
   *
   * Uses the catalog's own `woo_suggest`, the same data the new-feed
   * pre-population reads, so auto-map and pre-population never disagree.
   */
  const autoMapSuggested = (currency: string): number => {
    let mappedCount = 0;

    specRows.value.forEach((row) => {
      if (isRowMapped(row) || !row.wooSuggest) return;

      const { source, sourceValue } = parseWooSuggest(row.wooSuggest);
      if (!sourceValue) return;

      const definition = catalogEntries.value.find((entry) => entry.feedName === row.feedName);
      const index = row.attrIndex !== null ? row.attrIndex : mapSpecRow(row);
      const attribute = feedAttributes.value[index];
      if (!attribute) return;

      attribute.source = source;
      attribute.source_value = sourceValue;
      if (definition) {
        attribute.prefix = replaceCurrency(rawPrefixFor(row.feedName), currency);
        attribute.suffix = replaceCurrency(rawSuffixFor(row.feedName), currency);
      }
      mappedCount += 1;
    });

    if (mappedCount > 0) {
      clearValidationErrors();
    }

    return mappedCount;
  };

  /**
   * Read the catalog's raw prefix for an output tag.
   */
  const rawPrefixFor = (feedName: string): string => findDefinition(feedName)?.prefix ?? '';

  /**
   * Read the catalog's raw suffix for an output tag.
   */
  const rawSuffixFor = (feedName: string): string => findDefinition(feedName)?.suffix ?? '';

  /**
   * Find a catalog definition by output tag.
   */
  const findDefinition = (feedName: string) => {
    for (const groupName in channelAttributes.value) {
      const definitions = channelAttributes.value[groupName];
      for (const attributeLabel in definitions) {
        if (definitions[attributeLabel]?.feed_name === feedName) {
          return definitions[attributeLabel];
        }
      }
    }
    return undefined;
  };

  // ---------------------------------------------------------------------------
  // Output preview
  // ---------------------------------------------------------------------------

  /**
   * localStorage key for the remembered preview product of one feed.
   */
  const previewProductStorageKey = (id: string) => `adt-fm-preview-product-${id}`;

  /**
   * Remember which product the user chose, per feed.
   *
   * The choice is a viewing preference, not feed configuration, so it stays in
   * the browser rather than being written to the feed.
   */
  const setPreviewProduct = (productId: number | null) => {
    previewProductId.value = productId;

    if (!feedId.value) return;

    try {
      if (productId) {
        window.localStorage.setItem(previewProductStorageKey(feedId.value), String(productId));
      } else {
        window.localStorage.removeItem(previewProductStorageKey(feedId.value));
      }
    } catch {
      // Private browsing or a full quota. The preview still works for this
      // session; only the memory of the choice is lost.
    }
  };

  /**
   * Restore a previously chosen preview product for this feed.
   */
  const restorePreviewProduct = () => {
    if (!feedId.value) return;

    try {
      const stored = window.localStorage.getItem(previewProductStorageKey(feedId.value));
      previewProductId.value = stored ? Number(stored) || null : null;
    } catch {
      previewProductId.value = null;
    }
  };

  /**
   * Search published products for the preview picker.
   */
  const searchProducts = async (term: string) => {
    // A newer search supersedes this one: without the token, a slower earlier
    // request lands last and overwrites the newer results, and the shared
    // loading flag clears on whichever answers first.
    const token = ++productSearchId;

    try {
      productSearchLoading.value = true;

      const response = await api().get('adtribes/v1/field-mapping-preview/products', {
        params: { search: term },
      });

      if (token !== productSearchId) {
        return;
      }

      const products = (response.data as { products?: PreviewProductOption[] }).products;
      productOptions.value = Array.isArray(products) ? products : [];
    } catch {
      // A failed lookup leaves the previous options in place; the picker is a
      // convenience and must not break the pane.
      if (token === productSearchId) {
        productOptions.value = [];
      }
    } finally {
      if (token === productSearchId) {
        productSearchLoading.value = false;
      }
    }
  };

  /**
   * REST route for a WordPress object kind.
   */
  const objectRoute = (kind: 'page' | 'post') => (kind === 'page' ? 'wp/v2/pages' : 'wp/v2/posts');

  /**
   * Strip the HTML WordPress returns in `title.rendered`.
   */
  const plainTitle = (rendered: string): string => {
    const el = document.createElement('textarea');
    el.innerHTML = rendered ?? '';
    return el.value.replace(/<[^>]*>/g, '').trim();
  };

  /**
   * Search pages or posts for a page_url / post_url row.
   *
   * Core's own routes are used rather than a plugin route: they already handle
   * search, paging and permissions, and shipping the whole list with the page
   * cost 399 KB of inline JSON on a store with 10,000 posts.
   */
  const searchObjects = async (kind: 'page' | 'post', term: string) => {
    try {
      const response = await api().get(objectRoute(kind), {
        params: { search: term, per_page: 20, _fields: 'id,title', orderby: term ? 'relevance' : 'date' },
      });

      const items = Array.isArray(response.data) ? response.data : [];

      return items.map((item: { id: number; title?: { rendered?: string } }) => {
        const title = plainTitle(item.title?.rendered ?? '');
        objectTitles.value[`${kind}:${item.id}`] = title;
        return { value: String(item.id), label: title };
      });
    } catch {
      return [];
    }
  };

  /**
   * Look up the titles of every page/post the saved mapping already references.
   *
   * One request per kind, so a feed with page and post rows costs two calls on
   * load instead of a list of every published object.
   */
  const resolveObjectTitles = async () => {
    const wanted: Record<'page' | 'post', string[]> = { page: [], post: [] };

    feedAttributes.value.forEach((attribute) => {
      const kind = attribute.source === 'page_url' ? 'page' : attribute.source === 'post_url' ? 'post' : null;
      if (!kind || !attribute.source_value) return;
      if (objectTitles.value[`${kind}:${attribute.source_value}`]) return;
      if (!wanted[kind].includes(attribute.source_value)) wanted[kind].push(attribute.source_value);
    });

    await Promise.all(
      (['page', 'post'] as const).map(async (kind) => {
        if (wanted[kind].length === 0) return;

        try {
          const response = await api().get(objectRoute(kind), {
            params: { include: wanted[kind].join(','), per_page: 100, _fields: 'id,title' },
          });

          const items = Array.isArray(response.data) ? response.data : [];
          items.forEach((item: { id: number; title?: { rendered?: string } }) => {
            objectTitles.value[`${kind}:${item.id}`] = plainTitle(item.title?.rendered ?? '');
          });
        } catch {
          // A missing title falls back to the raw ID in the UI.
        }
      })
    );
  };

  /**
   * Load the variations of a variable product for the preview picker.
   *
   * Passing 0 clears the list, which is what a simple product needs.
   */
  const loadVariations = async (parentId: number) => {
    if (!parentId) {
      variationOptions.value = [];
      variationsTruncated.value = false;
      return;
    }

    try {
      const response = await api().get('adtribes/v1/field-mapping-preview/products', {
        params: { parent: parentId },
      });

      const data = response.data as { products?: PreviewProductOption[]; truncated?: boolean };
      variationOptions.value = Array.isArray(data.products) ? data.products : [];
      variationsTruncated.value = data.truncated === true;
    } catch {
      variationOptions.value = [];
      variationsTruncated.value = false;
    }
  };

  /**
   * Resolve the in-progress mapping against a sample product.
   *
   * Posts to a computation-only route: it persists nothing, so this is safe to
   * call on every edit. Results are per-attribute lines plus the rendered
   * `<item>` XML. Failures are swallowed into `previewError` — a preview must
   * never block editing.
   */
  const loadPreview = async () => {
    if (!feedId.value || feedId.value === 'new') {
      return;
    }

    try {
      previewLoading.value = true;
      previewError.value = null;
      previewUnsupported.value = null;

      const response = await api().post(
        `adtribes/v1/field-mappings/${encodeURIComponent(feedId.value)}/preview`,
        {
          feed_attributes: feedAttributes.value,
          product_id: previewProductId.value,
        }
      );

      const data = response.data as FieldMappingPreviewResponse;

      if (data.supported === false) {
        previewUnsupported.value =
          typeof data.reason === 'string' && data.reason
            ? data.reason
            : __('The output preview does not cover this feed.', 'woo-product-feed-pro');
        previewXml.value = '';
        previewLines.value = [];
        previewProduct.value = null;
        return;
      }

      previewUnsupported.value = null;
      previewXml.value = typeof data.xml === 'string' ? data.xml : '';
      previewLines.value = Array.isArray(data.lines) ? data.lines : [];
      previewProduct.value = data.product ?? null;

      // The server falls back to the newest product when no ID was sent; adopt
      // it so the picker shows what is really being previewed.
      if (!previewProductId.value && data.product?.id) {
        previewProductId.value = data.product.id;
      }

      // Previewing a variation means its siblings should be offered too.
      if (data.product?.parent) {
        await loadVariations(data.product.parent);
      }
    } catch (err) {
      previewError.value =
        err instanceof Error ? err.message : __('Could not build the preview.', 'woo-product-feed-pro');
    } finally {
      previewLoading.value = false;
    }
  };

  /**
   * Attributes the preview could not resolve, so the pane can say so instead of
   * quietly omitting them.
   */
  const notPreviewableCount = computed(() => previewLines.value.filter((line) => !line.previewable).length);

  /**
   * Reset store to initial state.
   */
  const resetStore = () => {
    loading.value = false;
    error.value = null;
    feedId.value = null;
    migrationRan.value = false;
    feedAttributes.value = [];
    channelAttributes.value = {};
    productFields.value = {};
    subAttributeRegistry.value = {};
    eliteGatedAttrGroups.value = {};
    search.value = '';
    activeGroup.value = 'all';
    onlyRequired.value = false;
    expandedGroups.value = new Set();
    previewLines.value = [];
    previewXml.value = '';
    previewProduct.value = null;
    previewError.value = null;
    previewProductId.value = null;
    productOptions.value = [];
    variationOptions.value = [];
    variationsTruncated.value = false;
    objectTitles.value = {};
    clearValidationErrors();
  };

  return {
    // State
    loading,
    error,
    feedId,
    migrationRan,
    feedAttributes,
    channelAttributes,
    productFields,
    subAttributeRegistry,
    eliteGatedAttrGroups,

    // Table view state
    search,
    activeGroup,
    onlyRequired,
    focusRowKey,
    density,
    showPreview,

    // Preview state
    previewLines,
    previewXml,
    previewProduct,
    previewLoading,
    previewError,
    previewUnsupported,
    objectTitles,
    previewProductId,
    productOptions,
    productSearchLoading,
    variationOptions,
    variationsTruncated,

    // Getters
    hasAttributes,
    specRows,
    visibleRows,
    groupedVisibleRows,
    renderedRows,
    expandedGroups,
    isBrowsingAll,
    groupSummary,
    overallSummary,
    isRowMapped,
    isRowInUse,
    toggleGroupExpanded,
    browseAllAttributes,
    notPreviewableCount,
    autoMapCandidates,

    // Validation
    isFormValid,
    getFieldErrors,
    hasValidationErrors,
    validationErrors,
    showValidation,

    // Actions
    loadFieldMappings,
    loadPreview,
    searchProducts,
    searchObjects,
    resolveObjectTitles,
    loadVariations,
    setPreviewProduct,
    restorePreviewProduct,
    prepopulateRequiredRows,
    mapSpecRow,
    autoMapSuggested,
    addAttribute,
    removeAttribute,
    updateAttribute,
    changeAttributeFeedName,
    getRegistryTags,
    applyRegistryDefaults,
    getRepeats,
    isNestedParent,
    canAddRepeat,
    addRepeat,
    updateRepeat,
    removeRepeat,
    moveRepeat,
    addSubAttribute,
    updateSubAttribute,
    removeSubAttribute,
    addSubAttributeGroup,
    removeSubAttributeGroup,
    setSubAttributeGroups,
    validateAttribute,
    updateValidationErrors,
    clearValidationErrors,
    resetStore,
  };
});
