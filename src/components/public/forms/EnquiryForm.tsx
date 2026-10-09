import type { ServiceId } from "@/lib/services"
import type { EnquiryKind } from "@/lib/service-pages"
import { RequestStaffForm } from "@/components/public/forms/RequestStaffForm"
import { CleaningQuoteForm } from "@/components/public/forms/CleaningQuoteForm"
import { CareEnquiryForm, TrainingEnquiryForm } from "@/components/public/forms/ContactForms"

/**
 * The right form for an enquiry kind, as listed in the 2026 plan (file 05,
 * "Enquiry forms and where they go"):
 *   staff     -> bookings.php
 *   cleaning  -> quotes.php?action=request
 *   care      -> contact.php, type care
 *   training  -> contact.php, type training
 */
export function EnquiryForm({ kind, service }: { kind: EnquiryKind; service?: ServiceId }) {
  switch (kind) {
    case "cleaning":
      return <CleaningQuoteForm />
    case "care":
      return <CareEnquiryForm />
    case "training":
      return <TrainingEnquiryForm />
    case "staff":
      return <RequestStaffForm service={service === "care" || service === "cleaning" || service === "security" ? service : undefined} />
  }
}
