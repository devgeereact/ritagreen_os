import { cva, type VariantProps } from "class-variance-authority"

/**
 * Website buttons: 44px tall, 16px semibold, 8px radius, from the 2026 mock.
 * A class helper rather than a component, so a <Link> and a <button> can both
 * wear it. Kept apart from the components so fast refresh keeps working.
 */
export const siteButton = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg border text-base font-semibold leading-none no-underline transition-colors duration-150 disabled:cursor-not-allowed disabled:border-border disabled:bg-muted disabled:text-muted-foreground",
  {
    variants: {
      variant: {
        primary: "border-transparent bg-primary text-primary-foreground hover:bg-[#2A4325]",
        secondary: "border-primary bg-card text-primary hover:bg-accent",
        light: "border-transparent bg-white text-[#1F3219] hover:bg-accent",
      },
      size: {
        md: "min-h-11 px-5",
        sm: "min-h-9 px-3.5 text-[15px]",
      },
    },
    defaultVariants: { variant: "primary", size: "md" },
  },
)
export type SiteButtonProps = VariantProps<typeof siteButton>
