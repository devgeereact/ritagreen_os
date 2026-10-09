import * as React from "react"
import { useNavigate } from "react-router-dom"

import { Button } from "@/components/ui/button"
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command"
import { useAuth } from "@/providers/auth-provider"
import { canAccess } from "@/lib/access"
import { DESTINATIONS, GROUP_ORDER } from "@/lib/app-routes"
import { useFeatures } from "@/hooks/use-features"

import { Search, ChevronRight } from "lucide-react"

/**
 * The ⌘K palette.
 *
 * It used to offer six fixed destinations, of which five did not exist in this
 * application at all: /analytics, /crm, /profile, /billing and /settings are not
 * routes here (the real ones live under /app), and "CRM" and "Billing" were
 * never features of RitaGreenOS -- they were left over from the dashboard
 * template this component came from. Choosing any of the five dropped the user
 * out of the CMS and onto the public 404 page; the sixth, "Dashboard", pointed
 * at "/" and took them to the marketing homepage.
 *
 * The destinations come from src/lib/app-routes.ts, shared with the document
 * title, and are filtered through canAccess() so the palette never offers a page
 * the user would be bounced off. Keywords live in that list so "invoice" finds
 * Invoicing and "staff" finds People without the label having to contain the
 * word.
 */
export function GlobalSearch() {
  const [open, setOpen] = React.useState(false)
  const navigate = useNavigate()
  const { user } = useAuth()
  const { isOn } = useFeatures()

  // Keyboard Shortcut (Ctrl + K / Cmd + K)
  React.useEffect(() => {
    const down = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault()
        setOpen((prev) => !prev)
      }
    }

    document.addEventListener("keydown", down)

    return () => {
      document.removeEventListener("keydown", down)
    }
  }, [])

  // Same gate as the sidebar and the router, so the palette cannot offer a page
  // this account would be redirected away from, nor an area the owner has
  // switched off.
  const allowed = React.useMemo(
    () => DESTINATIONS.filter((d) => canAccess(user, d.path) && (!d.feature || isOn(d.feature))),
    [user, isOn],
  )

  const runCommand = (path: string) => {
    setOpen(false)
    navigate(path)
  }

  return (
    <>
      {/* Icon Search Button */}
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(true)}
        aria-label="Search pages (Command K)"
        className="rounded-full [&_svg]:size-5 h-10 w-10 p-0"
      >
        <Search />
      </Button>

      {/* Command Dialog */}
      <CommandDialog open={open} onOpenChange={setOpen}>
        <div className="border-b">
          <CommandInput placeholder="Search pages…" className="h-12" />
        </div>

        <CommandList className="max-h-[420px] overflow-y-auto">
          <CommandEmpty>
            <div className="py-10 text-center text-sm text-muted-foreground">
              No results found.
            </div>
          </CommandEmpty>

          {GROUP_ORDER.map((group, i) => {
            const items = allowed.filter((d) => d.group === group)
            if (items.length === 0) return null
            return (
              <React.Fragment key={group}>
                {i > 0 && <CommandSeparator />}
                <CommandGroup heading={group}>
                  {items.map((d) => (
                    <CommandItem
                      key={d.path}
                      value={`${d.label} ${d.keywords ?? ""}`}
                      onSelect={() => runCommand(d.path)}
                      className="group cursor-pointer"
                    >
                      <d.icon className="mr-3 h-4 w-4 text-muted-foreground" />
                      <span>{d.label}</span>
                      <ChevronRight className="ml-auto h-4 w-4 opacity-0 transition-opacity group-data-[selected=true]:opacity-100" />
                    </CommandItem>
                  ))}
                </CommandGroup>
              </React.Fragment>
            )
          })}
        </CommandList>
      </CommandDialog>
    </>
  )
}
