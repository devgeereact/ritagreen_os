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
        <AccordionItem key={f.q} value={`faq-${i}`}>
          <AccordionTrigger className="text-left text-base font-semibold">
            {f.q}
          </AccordionTrigger>
          <AccordionContent className="text-muted-foreground">{f.a}</AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  )
}
