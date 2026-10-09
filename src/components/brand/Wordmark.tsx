import { cn } from "@/lib/utils"

/**
 * The website's "RitaGreen" wordmark: the name, set in the brand green.
 *
 * Text, not an image, because no logo file exists yet (2026 plan, file 05,
 * "Logo"). A professionally designed logo is a separate owner decision; when it
 * arrives it replaces this component and nothing else. The office app and the
 * sign-in pages keep the leaf mark in Logo.tsx.
 */
export function Wordmark({ className, tone = "brand" }: { className?: string; tone?: "brand" | "light" }) {
  return (
    <span
      className={cn(
        "text-[1.375rem] font-bold leading-none tracking-[-0.01em]",
        tone === "light" ? "text-white" : "text-[#33512D]",
        className,
      )}
    >
      RitaGreen
    </span>
  )
}
