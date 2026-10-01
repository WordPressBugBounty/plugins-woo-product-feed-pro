/***************************************************************************
 * Field Mapping Builder types
 ***************************************************************************
 *
 * TypeScript types for the `feed_attributes` schema and the REST payload of
 * GET /wp-json/adtribes/v1/field-mappings/{id}.
 *
 */

/**
 * Value source for a top-level feed attribute.
 * - `field`: mapped from a WooCommerce product field (`source_value` = field key)
 * - `static`: fixed text (`source_value` = the text)
 * - `page_url`: URL of a WordPress page (`source_value` = page ID)
 * - `post_url`: URL of a WordPress post (`source_value` = post ID)
 */
export type AttributeSource = 'field' | 'static' | 'page_url' | 'post_url';

/**
 * Value source for a sub-attribute row.
 * - `zone`: resolved from WooCommerce shipping zone data at generation time (read-only in UI)
 * - `field`: mapped from a WooCommerce product field
 * - `static`: fixed value
 * - `empty`: intentionally omitted from output
 * - `page_url`: URL of a WordPress page (`source_value` = page ID)
 * - `post_url`: URL of a WordPress post (`source_value` = post ID)
 *
 * Mirrors `Feed_Attributes_Migrator::SUB_SOURCES`. A value added on one side
 * only makes the save path discard the row without an error.
 */
export type SubAttributeSource = 'zone' | 'field' | 'static' | 'empty' | 'page_url' | 'post_url';

/**
 * A single sub-attribute row inside a repeating group.
 */
export interface SubAttribute {
  tag: string;
  source: SubAttributeSource;
  source_value: string;
  prefix: string;
  suffix: string;
}

/**
 * A repeating group of sub-attribute rows. Each group produces one parent
 * output element (e.g. one `<g:tax>`); `zones_auto` parents hold a single
 * template group expanded per WooCommerce shipping zone at generation time.
 *
 * A group of ONE row whose `tag` is empty is a scalar repeat instead: the value
 * belongs to the parent element itself, so the group emits a plain sibling such
 * as a second `<g:additional_image_link>`. `Repeatable_Attributes` owns that
 * shape on the PHP side — see its class docblock for why repeats share this key
 * rather than getting one of their own.
 */
export type SubAttributeGroup = SubAttribute[];

/**
 * A single row of the `feed_attributes` schema.
 */
export interface FeedAttribute {
  feed_name: string;
  source: AttributeSource;
  source_value: string;
  prefix: string;
  suffix: string;
  is_custom: boolean;
  zones_auto?: boolean;
  sub_attributes?: SubAttributeGroup[];
}

/**
 * Catalog of selectable attributes grouped by section label, e.g.
 * `{ 'Default fields': { title: 'Title', description: 'Description' } }`.
 * Used for both the channel attribute catalog and the product fields list.
 */
export interface AttributeCatalog {
  [groupName: string]: {
    [attributeKey: string]: string;
  };
}

/**
 * A single channel attribute definition, as shipped verbatim from
 * `WooSEA_{fields}::get_channel_attributes()` through the REST payload.
 * `format === 'required'` rows are pre-populated for feeds with no mapping;
 * `woo_suggest` / `prefix` / `suffix` carry the pre-population defaults.
 */
export interface ChannelAttributeDefinition {
  name?: string;
  feed_name: string;
  format?: string;
  /**
   * Output tag whose mapping relaxes this attribute's `required` format, e.g.
   * `g:mpn` carries `required_unless: 'g:gtin'` because Google asks for an MPN
   * only when the product has no GTIN. Absent for unconditional attributes.
   */
  required_unless?: string;
  /**
   * The mirror of `required_unless`: this attribute is required only while
   * another tag is mapped to one particular static value. `g:availability_date`
   * carries `{ feed_name: 'g:availability', static: 'preorder' }`, because
   * Google asks for an availability date in that one availability state.
   *
   * The gate is a STATIC value on purpose — a value read from a product field
   * is only known per product at generation time, so the editor cannot resolve
   * it. `notice` is the channel's explanation, shown while the gate is armed.
   */
  required_when?: {
    feed_name: string;
    static: string;
    notice?: string;
  };
  woo_suggest?: string;
  prefix?: string;
  suffix?: string;
  /**
   * The closed set of values the channel accepts for this attribute, when it has
   * one. Google restricts ten of the Detailed product description attributes this
   * way - `gender` to male/female/unisex, and so on - and a value outside the set
   * is an item-level disapproval in Merchant Center, not a feed error. Absent for
   * free-text attributes.
   */
  allowed_values?: string[];
  /**
   * How many times the channel's specification allows this attribute. Absent or
   * `1` means single-valued. Declared in the channel class and read verbatim —
   * making a further attribute repeatable is a one-line change there.
   */
  repeatable?: number | string;
  /** The values the specification documents, offered as suggestions on a static value. */
  choices?: string[];
}

/**
 * The channel attribute catalog: groups keyed by section label, each holding
 * definitions keyed by the attribute's display label.
 */
export interface ChannelAttributeCatalog {
  [groupName: string]: {
    [attributeLabel: string]: ChannelAttributeDefinition;
  };
}

/**
 * A registry definition of a default sub-attribute for a known parent tag.
 */
export interface SubAttributeRegistryEntry {
  tag: string;
  label: string;
  default_source: SubAttributeSource;
  default_value?: string;
}

/**
 * Default sub-attribute definitions for the feed's channel, keyed by parent
 * output tag (e.g. `g:shipping`, `g:tax`, `g:certification`).
 */
export interface SubAttributeRegistry {
  [parentTag: string]: SubAttributeRegistryEntry[];
}

/**
 * Payload of GET /wp-json/adtribes/v1/field-mappings/{id}.
 */
export interface FieldMappingsApiResponse {
  feed_attributes: FeedAttribute[];
  channel_attributes: ChannelAttributeCatalog;
  product_fields: AttributeCatalog;
  sub_attribute_registry: SubAttributeRegistry;
  migration_ran?: boolean;
  eliteGatedAttrGroups?: Record<string, string>;
}

/**
 * Result of validating a custom feed name.
 */
export interface FeedNameValidationResult {
  valid: boolean;
  message?: string;
}

/**
 * Requirement level of a channel attribute, from the catalog's `format` key.
 */
export type RequirementLevel = 'required' | 'recommended' | 'optional';

/**
 * One row of the field mapping table.
 *
 * The table renders the channel's WHOLE specification, so a row exists for every
 * catalog attribute whether or not the user mapped it. `attrIndex` is the join:
 * a number indexes into `feedAttributes` (the row is mapped and serializes), and
 * `null` means the row is inert — displayed, but absent from the saved payload
 * and therefore absent from the generated feed.
 */
export interface SpecRow {
  /** Stable key for `v-for`, unique across catalog and custom rows. */
  key: string;
  /** Output tag, e.g. `g:price`. */
  feedName: string;
  /** Display label from the catalog, falling back to the output tag. */
  label: string;
  /** Catalog group label, e.g. `Basic product data`. */
  group: string;
  /**
   * Requirement level for this channel, resolved against the current mapping:
   * a conditionally required attribute reads `recommended` once the tag its
   * `required_unless` names has a value, and an attribute required by only one
   * mapped value reads `required` once its `required_when` gate is armed.
   */
  format: RequirementLevel;
  /** Index into `feedAttributes`, or null when the row is unmapped and inert. */
  attrIndex: number | null;
  /** True for user-added custom fields, which have no catalog entry. */
  isCustom: boolean;
  /** The catalog's suggested source, used by auto-map. */
  wooSuggest: string;
  /** Closed value set from the catalog, or empty when the attribute is free text. */
  allowedValues: string[];
  /** Maximum values this attribute may emit; 1 for an ordinary single-valued row. */
  repeatMax: number;
  /** Documented values for the attribute, offered as suggestions on a static value. */
  choices: string[];
  /**
   * The channel's explanation for a `required_when` rule, non-empty while that
   * rule is arming this row OR while its gate tag carries any static value —
   * choosing one is the moment the rule is worth knowing about. The row renders
   * it inline, so the reason sits next to the badge it explains.
   */
  requirementNotice: string;
}

/**
 * Per-attribute output line returned by the preview endpoint.
 */
export interface PreviewLine {
  feed_name: string;
  value: string;
  nested: boolean;
  groups?: number;
  previewable: boolean;
}

/**
 * One product in the preview pane's product picker.
 */
export interface PreviewProductOption {
  id: number;
  name: string;
  sku: string;
  /** Present on parent products: whether a variation step should be offered. */
  has_variations?: boolean;
  /** Present on variations: the parent product's ID. */
  parent?: number;
}

/**
 * Payload of POST /wp-json/adtribes/v1/field-mappings/{id}/preview.
 */
export interface FieldMappingPreviewResponse {
  /**
   * False when the feed's channel does not generate through `Feed_Writer_XML`.
   * Generation forks on the same predicate, so the pane explains the limit
   * rather than rendering Google-shaped output the feed can never contain.
   */
  supported?: boolean;
  /** Why the preview is unavailable, when `supported` is false. */
  reason?: string;
  xml: string;
  lines: PreviewLine[];
  product: {
    id: number;
    name: string;
    sku: string;
    image: string;
    /** The parent product ID when a variation is being previewed, else 0. */
    parent: number;
    /** The variation's attribute summary, e.g. "Color: Blue". Empty otherwise. */
    variation: string;
  };
}
