import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"

export function Faqs({
  items,
  className,
}: {
  items: readonly { q: string; a: string }[]
  className?: string
}) {
  return (
    <Accordion type="single" collapsible className={className}>
      {items.map((f, i) => (
        <AccordionItem
          key={f.q}
          value={`faq-${i}`}
          className="surface mb-3 border-b-0 px-5 transition-colors data-[state=open]:border-primary/30"
        >
          <AccordionTrigger className="text-left text-base font-semibold hover:no-underline">
            {f.q}
          </AccordionTrigger>
          <AccordionContent className="leading-relaxed text-muted-foreground">{f.a}</AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  )
}
