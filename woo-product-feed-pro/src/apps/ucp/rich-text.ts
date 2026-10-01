/**
 * Render translated copy that carries markup without `v-html`.
 *
 * Several strings in this app mix a sentence with emphasized words or a
 * field path. Feeding `sprintf( __( '…%1$s…' ), '<strong>' )` into `v-html`
 * would render whatever the loaded translation contains as HTML, so a
 * tampered `.mo` file becomes script execution on an admin screen. These
 * helpers keep the same translator-facing strings (identical placeholders,
 * so no re-translation) but return plain text segments the template renders
 * as real elements — markup in a translation can then only ever be text.
 */

/** An element a segment of translated copy should render as. */
export type RichTextTag = 'span' | 'strong' | 'code';

/** One renderable segment of a translated string. */
export interface RichTextPart {
  text: string;
  tag: RichTextTag;
}

/**
 * What a `%n$s` placeholder means.
 *
 * Without `text` the placeholder opens a wrapper that closes at the next
 * placeholder (the `%1$s…%2$s` pattern). With `text` it stands alone and is
 * replaced by that literal (a non-translatable code snippet, say).
 */
export interface RichTextMarker {
  tag: RichTextTag;
  text?: string;
}

/**
 * Split a translated template into renderable parts.
 *
 * @param template Translated string containing `%n$s` placeholders.
 * @param markers  One entry per placeholder, in order; `null` closes the
 *                 currently open wrapper.
 * @return Segments to render, in order.
 */
export function richTextParts(template: string, markers: Array<RichTextMarker | null>): RichTextPart[] {
  const parts: RichTextPart[] = [];
  const token = /%(\d+)\$s/g;

  let cursor = 0;
  let open: RichTextMarker | null = null;
  let match: RegExpExecArray | null;

  const push = (text: string, tag: RichTextTag): void => {
    if (text !== '') {
      parts.push({ text, tag });
    }
  };

  while ((match = token.exec(template)) !== null) {
    const literal = template.slice(cursor, match.index);
    cursor = match.index + match[0].length;

    if (open) {
      // Everything up to this placeholder belongs inside the open wrapper,
      // and the placeholder itself closes it.
      push(literal, open.tag);
      open = null;
      continue;
    }

    push(literal, 'span');

    const marker = markers[Number(match[1]) - 1] ?? null;

    if (marker?.text !== undefined) {
      push(marker.text, marker.tag);
    } else if (marker) {
      open = marker;
    }
  }

  push(template.slice(cursor), open ? open.tag : 'span');

  return parts;
}

/** Markers for a string whose placeholders only wrap emphasized words. */
export function strongPairs(count: number): Array<RichTextMarker | null> {
  return Array.from({ length: count * 2 }, (_unused, index) => (index % 2 === 0 ? { tag: 'strong' as const } : null));
}

/**
 * Whether a URL is safe to put in an `href`.
 *
 * Vue does not filter `javascript:` or `data:` URIs out of a bound `:href`,
 * and these URLs arrive from REST responses. Today they are plugin-built,
 * but the binding is one server-side change away from being user-influenced,
 * so the scheme is checked here rather than assumed.
 *
 * @param url Candidate URL.
 * @return True for http(s) and site-relative URLs.
 */
export function isSafeHref(url: unknown): url is string {
  if (typeof url !== 'string' || url.trim() === '') {
    return false;
  }

  const candidate = url.trim();

  // Relative URLs carry no scheme and cannot execute script.
  if (candidate.startsWith('/') || candidate.startsWith('#') || candidate.startsWith('?')) {
    return true;
  }

  try {
    return ['http:', 'https:'].includes(new URL(candidate, window.location.origin).protocol);
  } catch {
    return false;
  }
}

/**
 * A URL safe to bind to an `href`, or undefined when it is not.
 *
 * @param url Candidate URL.
 * @return The URL, or undefined so the attribute is dropped entirely.
 */
export function safeHref(url: unknown): string | undefined {
  return isSafeHref(url) ? url.trim() : undefined;
}
