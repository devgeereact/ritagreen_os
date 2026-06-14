import { AlertCircle, Inbox } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"

// Consistent loading / error / empty states for CMS screens.
export function LoadingBlock({ rows = 3 }: { rows?: number }) {
  return (
    <div className="space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-12 w-full" />
      ))}
    </div>
  )
}

export function ErrorBlock({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-destructive/30 bg-destructive/5 py-14 text-center">
      <span className="grid size-12 place-items-center rounded-lg bg-destructive/10 text-destructive">
        <AlertCircle className="size-6" />
      </span>
      <p className="mt-4 max-w-sm text-sm text-muted-foreground">{message}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-4" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

export function EmptyBlock({ title, hint, action }: { title: string; hint?: string; action?: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/30 py-14 text-center">
      <span className="grid size-12 place-items-center rounded-lg bg-primary/10 text-primary">
        <Inbox className="size-6" />
      </span>
      <p className="mt-4 font-semibold">{title}</p>
      {hint && <p className="mt-1.5 max-w-sm text-sm leading-relaxed text-muted-foreground">{hint}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}
