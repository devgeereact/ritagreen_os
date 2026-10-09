// schema.org JSON-LD for the public pages, built from the same company facts
// the footer shows. Passed to useSeo({ jsonLd }), which writes one
// <script type="application/ld+json"> per page.
//
// No street address: the registered office is not confirmed, and a wrong
// address in structured data is worse than none. No ratings or reviews: there
// are no verified ones to cite. No JobPosting yet: role pages arrive in S7.

import type { CompanyDetails } from "@/lib/company"
import { SITE_ORIGIN } from "@/lib/public-routes"

type Ld = Record<string, unknown>

const ORG_ID = `${SITE_ORIGIN}/#organisation`

function organisationNode(c: CompanyDetails): Ld {
  return {
    "@type": ["EmploymentAgency", "LocalBusiness"],
    "@id": ORG_ID,
    name: c.tradingName,
    legalName: c.legalName,
    url: `${SITE_ORIGIN}/`,
    logo: `${SITE_ORIGIN}/icon-512.png`,
    image: `${SITE_ORIGIN}/og.png`,
    telephone: c.phone.display,
    email: c.email,
    description: "Healthcare staffing, cleaning, security and training in London.",
    areaServed: { "@type": "City", name: "London" },
    identifier: { "@type": "PropertyValue", propertyID: "Companies House company number", value: c.companyNo },
    openingHoursSpecification: {
      "@type": "OpeningHoursSpecification",
      dayOfWeek: c.openingHours.days,
      opens: c.openingHours.opens,
      closes: c.openingHours.closes,
    },
    sameAs: c.socials.map((s) => s.href),
    ...(c.registeredAddress ? { address: c.registeredAddress } : {}),
  }
}

/** Home page: the company as an employment agency and a local business. */
export function homeLd(c: CompanyDetails): Ld {
  return {
    "@context": "https://schema.org",
    "@graph": [
      organisationNode(c),
      { "@type": "WebSite", "@id": `${SITE_ORIGIN}/#website`, url: `${SITE_ORIGIN}/`, name: c.tradingName, publisher: { "@id": ORG_ID } },
    ],
  }
}

/** A service page. */
export function serviceLd(
  c: CompanyDetails,
  page: { path: string; serviceType: string; title: string; seoDescription: string },
): Ld {
  return {
    "@context": "https://schema.org",
    "@type": "Service",
    name: page.title,
    serviceType: page.serviceType,
    description: page.seoDescription,
    url: `${SITE_ORIGIN}${page.path}`,
    areaServed: { "@type": "City", name: "London" },
    provider: { "@type": "EmploymentAgency", "@id": ORG_ID, name: c.tradingName, telephone: c.phone.display, url: `${SITE_ORIGIN}/` },
  }
}

/** A news post. */
export function articleLd(
  c: CompanyDetails,
  post: { slug: string; title: string; excerpt: string; date: string; image: string },
): Ld {
  const image = post.image.startsWith("http") ? post.image : `${SITE_ORIGIN}${post.image}`
  return {
    "@context": "https://schema.org",
    "@type": "Article",
    headline: post.title,
    description: post.excerpt,
    ...(post.date ? { datePublished: post.date } : {}),
    image,
    mainEntityOfPage: `${SITE_ORIGIN}/news/${post.slug}`,
    author: { "@type": "Organization", name: c.tradingName, url: `${SITE_ORIGIN}/` },
    publisher: { "@type": "Organization", name: c.tradingName, logo: { "@type": "ImageObject", url: `${SITE_ORIGIN}/icon-512.png` } },
  }
}
