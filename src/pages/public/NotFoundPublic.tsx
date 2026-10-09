import { Link } from "react-router-dom"
import { Container } from "@/components/public/section"
import { siteButton } from "@/components/public/site-button"
import { useSeo } from "@/hooks/use-seo"

export default function NotFoundPublic() {
  // noindex, and no canonical. Without it this page inherited the static
  // canonical in index.html and told Google every mistyped URL was a duplicate
  // of the homepage.
  useSeo({
    title: "Page not found",
    description: "The page may have moved or never existed. Try the home page or get in touch.",
    noindex: true,
  })

  return (
    <Container className="py-20 md:py-28">
      <p className="eyebrow">Page not found</p>
      <h1 className="mt-2 max-w-[22ch] text-[2rem] leading-tight md:text-[2.75rem]">We cannot find that page</h1>
      <p className="mt-4 max-w-[52ch] text-lg text-muted-foreground">
        It may have moved when we rebuilt the website, or the address may be mistyped. These will get you back on track.
      </p>
      <div className="mt-7 flex flex-wrap gap-3">
        <Link to="/" className={siteButton()}>
          Go to the home page
        </Link>
        <Link to="/contact" className={siteButton({ variant: "secondary" })}>
          Contact us
        </Link>
      </div>
    </Container>
  )
}
