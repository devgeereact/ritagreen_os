import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"
import { cn } from "@/lib/utils"

/** Questions and answers in one bordered list. Each answer opens in place. */
export function Faqs({
  items,
  className,
}: {
  items: readonly { q: string; a: string }[]
  className?: string
}) {
  return (
    <Accordion
      type="single"
      collapsible
      className={cn("divide-y divide-border rounded-lg border border-border bg-card", className)}
    >
      {items.map((f, i) => (
        <AccordionItem key={f.q} value={`faq-${i}`} className="border-b-0 px-5">
          <AccordionTrigger className="py-4 text-left text-base font-semibold hover:no-underline">
            {f.q}
          </AccordionTrigger>
          <AccordionContent className="pb-5 text-base leading-relaxed text-muted-foreground">{f.a}</AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  )
}
