import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { useSeo } from "@/hooks/use-seo"

export default function NotFoundPublic() {
  useSeo({
    title: "Page not found",
    description: "The page may have moved or never existed. Try the homepage or get in touch.",
  })

  return (
    <div className="bg-hero-pro relative flex min-h-[70vh] items-center justify-center overflow-hidden px-4 py-28">
      <div aria-hidden className="bg-hero-grid pointer-events-none absolute inset-0" />
      <div className="relative flex max-w-md flex-col items-center text-center animate-fade-up">
        <p className="font-bold leading-none text-primary text-7xl md:text-8xl">404</p>
        <h1 className="mt-6 text-2xl font-bold">We can't find that page</h1>
        <p className="mt-3 leading-relaxed text-muted-foreground">
          The page may have moved or never existed. Try the homepage or get in touch.
        </p>
        <div className="mt-7 flex gap-3">
          <Button asChild className="shadow-lift"><Link to="/">Back to home</Link></Button>
          <Button asChild variant="outline"><Link to="/contact">Contact us</Link></Button>
        </div>
      </div>
    </div>
  )
}
