import { cn } from "@/lib/utils"

// The RitaGreen wordmark. "Green" is the brand, so it carries the brand colour.
//
// The mark is drawn from tokens rather than hexes so it follows the theme: the
// tile is the primary colour, the leaf is the colour text takes on it, and the
// vein is cut back out in the tile colour. public/favicon.svg is the same mark
// in fixed colours, for places a stylesheet cannot reach.
export function Logo({
  className,
  variant = "dark",
}: {
  className?: string
  variant?: "dark" | "light"
}) {
  return (
    <span className={cn("inline-flex items-center gap-2 font-bold tracking-tight", className)}>
      <span className="grid size-8 place-items-center rounded-lg bg-primary text-primary-foreground">
        <svg viewBox="0 0 32 32" className="size-5" aria-hidden="true">
          <path
            d="M16 25c-5 0-9-3.6-9-9 0-3.4 2-6 4.8-7.4C14 7.2 17 7 21 7h4v4c0 4.4-1.2 7.8-3.7 10-1.4 1.4-3.3 2-5.3 2z"
            fill="currentColor"
          />
          <path
            d="M11 23c2-5.1 5.3-8.4 10-10.3"
            fill="none"
            stroke="hsl(var(--primary))"
            strokeWidth="1.8"
            strokeLinecap="round"
          />
        </svg>
      </span>
      <span className={cn("text-lg", variant === "light" ? "text-white" : "text-foreground")}>
        Rita<span className={variant === "light" ? "text-white/80" : "text-primary"}>Green</span>
      </span>
    </span>
  )
}
