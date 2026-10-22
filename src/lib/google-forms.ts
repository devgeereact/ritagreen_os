// Building Google Form addresses from a stored form id, and nothing else.
//
// The forms directory stores only the id of an approved Google Form
// (rg_parse_google_form() in server/pure.php refuses every other address). The
// website builds the iframe and the "open in a new tab" link from that id here,
// so a pasted address, a redirector or a script can never reach a public page.
// The id is checked again on the way in: a value that is not a plain Google
// Forms id produces no address at all, and the page shows its fallback instead.
//
// Plain functions with no imports, so src/lib/__tests__/google-forms.test.ts can
// test them in the node environment.

const FORM_ID = /^[A-Za-z0-9_-]{20,200}$/

/** Is this a Google Forms responder id, as website_forms.google_form_id holds? */
export function isGoogleFormId(id: unknown): id is string {
  return typeof id === "string" && FORM_ID.test(id)
}

/** The address respondents use, for the "Open the form in a new tab" link. */
export function googleFormOpenUrl(id: unknown): string | null {
  return isGoogleFormId(id) ? `https://docs.google.com/forms/d/e/${id}/viewform` : null
}

/** The iframe src for an embedded form: the same address with Google's embedded flag. */
export function googleFormEmbedSrc(id: unknown): string | null {
  const url = googleFormOpenUrl(id)
  return url ? `${url}?embedded=true` : null
}
