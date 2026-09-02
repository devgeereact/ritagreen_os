import { Link, useRouteError, isRouteErrorResponse } from "react-router-dom"
import { Button } from "@/components/ui/button"

const MESSAGES: Record<number, { title: string; body: string }> = {
  400: { title: "Bad request", body: "Something about that request wasn't right." },
  403: { title: "No access", body: "You don't have permission to view this page." },
  404: { title: "Page not found", body: "The page you're looking for doesn't exist or has moved." },
  500: { title: "Server error", body: "Something went wrong on our side. Please try again." },
  503: { title: "Service unavailable", body: "We're temporarily offline for maintenance. Back shortly." },
}

export default function ErrorPage() {
  const error = useRouteError()
  let status = 500
  let detail = ""

  if (isRouteErrorResponse(error)) {
    status = error.status
    // A router status text ("Not Found") is written by us and safe to show.
    detail = error.statusText || ""
  } else if (error instanceof Error) {
    // A thrown Error's message is not. It is whatever the failing code said --
    // a module path, a property name, a fragment of internal state -- and it was
    // being rendered straight onto the page for anyone who tripped a bug. It is
    // useful while developing and it is a disclosure in production, so it stays
    // on the console, where a developer can still reach it, and goes no further.
    if (import.meta.env.DEV) detail = error.message
    else console.error("Route error:", error)
  }

  const info = MESSAGES[status] ?? MESSAGES[500]

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-muted px-6 text-center">
      <p className="text-6xl font-bold text-primary">{status}</p>
      <h1 className="mt-4 text-2xl font-bold tracking-tight">{info.title}</h1>
      <p className="mt-2 max-w-md text-muted-foreground">{info.body}</p>
      {detail && <p className="mt-1 text-xs text-muted-foreground/70">{detail}</p>}
      <div className="mt-6 flex gap-3">
        <Button asChild><Link to="/">Back to home</Link></Button>
        <Button asChild variant="outline"><Link to="/app">Go to dashboard</Link></Button>
      </div>
    </div>
  )
}
