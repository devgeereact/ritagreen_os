// The website's photographs.
//
// PLACEHOLDERS. These five are licensed stock images carried over from the
// previous site. They do not show RitaGreen staff, clients, sites or the people
// we support, and nothing on the site may caption or describe them as if they
// did. The alt text describes the scene and nothing more.
//
// REPLACE THEM with the commissioned half-day shoot in the 2026 plan (file 05,
// "Look and feel": staff in uniform, a training session, the office, a cleaning
// team at work, an officer on site, all with signed consent). Swap the files in
// public/images/divisions/ and update the alt text here; no page needs changing.

export type SiteImage = { src: string; alt: string }

export const SITE_IMAGES = {
  care: {
    src: "/images/divisions/care.jpg",
    alt: "A care worker in a blue tunic helping an older woman with a drawing activity at a kitchen table",
  },
  cleaning: {
    src: "/images/divisions/cleaning.jpg",
    alt: "A cleaner mopping the floor of a bright, modern building corridor",
  },
  security: {
    src: "/images/divisions/security.jpg",
    alt: "A security officer standing at the lit entrance of an office building in the evening",
  },
  training: {
    src: "/images/divisions/training.jpg",
    alt: "Adults sitting at desks in a classroom, listening to a session",
  },
  company: {
    src: "/images/divisions/company.jpg",
    alt: "Four people talking around a meeting table in an office",
  },
} satisfies Record<string, SiteImage>
