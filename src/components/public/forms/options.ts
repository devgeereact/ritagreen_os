// Choices offered by the website's enquiry forms.
//
// The cleaning lists are posted as keys and checked by quotes.php against
// rg_cleaning_site_types() and rg_cleaning_frequencies() in server/pure.php.
// Change both together: a key here the server does not know is refused.

export type Option = { value: string; label: string }

export const CLEANING_SITE_TYPES: Option[] = [
  { value: "residential", label: "Residential block or communal areas" },
  { value: "void", label: "Void property or end of tenancy" },
  { value: "school", label: "School" },
  { value: "construction", label: "Construction site (builders clean)" },
  { value: "office", label: "Office or commercial premises" },
  { value: "care", label: "Care setting" },
  { value: "other", label: "Something else" },
]

export const CLEANING_FREQUENCIES: Option[] = [
  { value: "one_off", label: "One-off clean" },
  { value: "daily", label: "Daily" },
  { value: "several_weekly", label: "Several times a week" },
  { value: "weekly", label: "Weekly" },
  { value: "fortnightly", label: "Fortnightly" },
  { value: "monthly", label: "Monthly" },
  { value: "not_sure", label: "Not sure yet" },
]

/** Services that supply staff to a site. Training is booked, not staffed. */
export const STAFF_SERVICES: Option[] = [
  { value: "care", label: "Healthcare: carers, support workers, nurses" },
  { value: "cleaning", label: "Cleaning: cleaners and supervisors" },
  { value: "security", label: "Security: officers and door supervisors" },
]

export const CARE_FOR: Option[] = [
  { value: "Myself", label: "Myself" },
  { value: "A family member or friend", label: "A family member or friend" },
  { value: "Someone I support in my work", label: "Someone I support in my work" },
]

export const CARE_NEED: Option[] = [
  { value: "Support at home", label: "Support at home" },
  { value: "Supported living", label: "Supported living" },
  { value: "Not sure yet", label: "Not sure yet, I would like to talk it through" },
]

export const CALLBACK_TIMES: Option[] = [
  { value: "Morning (9am to 12pm)", label: "Morning (9am to 12pm)" },
  { value: "Afternoon (12pm to 5pm)", label: "Afternoon (12pm to 5pm)" },
  { value: "Any time in office hours", label: "Any time in office hours" },
]

// Broad course areas, not a timetable: the course list is still to be confirmed
// (2026 plan, file 01), so the form asks what somebody needs rather than
// offering dates that may not exist.
export const COURSES: Option[] = [
  { value: "Care Certificate", label: "Care Certificate" },
  { value: "Mandatory training", label: "Mandatory training (for example moving and handling, safeguarding)" },
  { value: "Refresher training", label: "Refresher training" },
  { value: "Not sure yet", label: "Not sure yet" },
]

export const GENERAL_TOPICS: Option[] = [
  { value: "care", label: "Healthcare" },
  { value: "cleaning", label: "Cleaning" },
  { value: "security", label: "Security" },
  { value: "academy", label: "Training" },
  { value: "other", label: "Something else" },
]

export function labelFor(options: Option[], value: string): string {
  return options.find((o) => o.value === value)?.label ?? value
}
