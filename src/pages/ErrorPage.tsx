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
    detail = error.statusText || ""
  } else if (error instanceof Error) {
    detail = error.message
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
