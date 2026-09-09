// The storage-notice reopen channel.
//
// Lives outside CookieConsent.tsx because a module that exports both a component
// and a plain function loses fast refresh for the whole file
// (react-refresh/only-export-components). The footer link needs the function,
// not the component.

/** Event name the notice listens on. Shared so the two sides cannot drift. */
export const STORAGE_NOTICE_EVENT = "rg:storage-notice"

/** Reopen the storage notice — the footer link calls this. */
export function openStorageNotice(): void {
  window.dispatchEvent(new CustomEvent(STORAGE_NOTICE_EVENT))
}
