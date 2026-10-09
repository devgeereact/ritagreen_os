import { useState } from "react"
import { ExternalLink } from "lucide-react"
import { Link } from "react-router-dom"
import { siteButton } from "@/components/public/site-button"
import { googleFormEmbedSrc, googleFormOpenUrl } from "@/lib/google-forms"
import type { CompanyDetails } from "@/lib/company"

// The application form block on a role page (2026 plan, file 06).
//
// The website makes no third-party requests by default, and that is
// load-bearing (CLAUDE.md, docs/COOKIES.md). A Google Form is a third-party
// frame, so it is not in the page until the visitor presses the button: before
// then nothing on this page contacts Google, and Google can set nothing. The
// "open in a new tab" link and the phone number are always there, so a form
// that cannot load (blocked, offline, an old browser) is never an empty box.
//
// The iframe is built from the stored form id only (src/lib/google-forms.ts).
// There is no sandbox attribute: a sandbox that let Google Forms work would need
// scripts, forms, same-origin and popups, which adds little for a cross-origin
// frame, and a wrong one breaks submission silently. The CSP's frame-src limits
// frames to docs.google.com instead (public/.htaccess).
//
// The page never says "application received": it cannot see inside Google's
// form, and Google shows its own confirmation.

export function GoogleFormEmbed({
  formId,
  display,
  roleTitle,
  company,
}: {
  formId: string
  display: "embedded" | "link"
  roleTitle: string
  company: CompanyDetails
}) {
  const [loaded, setLoaded] = useState(false)
  const src = googleFormEmbedSrc(formId)
  const openUrl = googleFormOpenUrl(formId)

  const newTab = openUrl && (
    <a href={openUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 font-semibold text-primary underline underline-offset-4">
      Open the form in a new tab
      <ExternalLink aria-hidden="true" className="size-4" />
      <span className="sr-only"> (opens Google Forms in a new tab)</span>
    </a>
  )

  const phoneLine = (
    <p className="text-[15px] text-muted-foreground">
      Cannot use the form? Call the office on{" "}
      <a href={company.phone.href} className="font-semibold text-foreground underline underline-offset-4">
        {company.phone.display}
      </a>
      {" "}({company.officeHours}) and we will take your details by phone.
    </p>
  )

  if (!src || !openUrl) {
    return (
      <div className="grid gap-4">
        <p>The application form is not available just now.</p>
        {phoneLine}
      </div>
    )
  }

  if (display === "link") {
    return (
      <div className="grid gap-4">
        <p>
          Applications for this role are made on a short Google Form. It asks only what we need to start: your
          contact details, your right to work, your experience and when you can work. No documents.
        </p>
        <p>
          <a href={openUrl} target="_blank" rel="noopener noreferrer" className={siteButton()}>
            Open the application form
            <span className="sr-only"> (opens Google Forms in a new tab)</span>
          </a>
        </p>
        {phoneLine}
      </div>
    )
  }

  return (
    <div className="grid gap-4">
      <p className="text-[15px]">Prefer a full screen? {newTab}</p>
      {loaded ? (
        <iframe
          src={src}
          title={`Application form for ${roleTitle} (Google Forms)`}
          className="h-[1500px] w-full rounded-lg border border-border bg-white md:h-[1300px]"
          referrerPolicy="no-referrer"
          loading="lazy"
        >
          Loading the form. If it does not appear, use the link above to open it in a new tab.
        </iframe>
      ) : (
        <div className="rounded-lg border border-border bg-background p-5 sm:p-8">
          <h3 className="text-lg font-semibold">The application form is hosted by Google</h3>
          <p className="mt-2 text-[15px] text-muted-foreground">
            Loading it here connects your browser to Google Forms, which may set its own cookies. Nothing is loaded
            from Google until you choose to. The form asks for no documents.{" "}
            <Link to="/cookies" className="underline underline-offset-4">
              Cookies and storage
            </Link>
          </p>
          <button type="button" onClick={() => setLoaded(true)} className={`${siteButton()} mt-5`}>
            Load the application form from Google
          </button>
        </div>
      )}
      {phoneLine}
    </div>
  )
}
