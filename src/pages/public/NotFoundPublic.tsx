import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"

export default function NotFoundPublic() {
  return (
    <div className="mx-auto flex max-w-6xl flex-col items-center px-4 py-28 text-center">
      <p className="text-6xl font-bold text-primary">404</p>
      <h1 className="mt-4 text-2xl font-bold">We can't find that page</h1>
      <p className="mt-2 max-w-md text-muted-foreground">
        The page may have moved or never existed. Try the homepage or get in touch.
      </p>
      <div className="mt-6 flex gap-3">
        <Button asChild><Link to="/">Back to home</Link></Button>
        <Button asChild variant="outline"><Link to="/contact">Contact us</Link></Button>
      </div>
    </div>
  )
}
