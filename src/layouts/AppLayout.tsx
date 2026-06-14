import { Outlet, useLocation } from "react-router-dom"
import { AppSidebar } from "@/components/app-sidebar"
import { AiAssistant } from "@/components/app/ai-assistant"
import { NotificationDropdown } from "@/components/notification-dropdown"

import {
  SidebarInset,
  SidebarProvider,
  SidebarTrigger,
} from "@/components/ui/sidebar"
import { ThemeToggle } from "@/components/theme-toggle"
import { GlobalSearch } from "@/components/global-search"

import Footer from "@/layouts/Footer"

import { useEffect, useState } from "react"
import { cn } from "@/lib/utils"
import { UserDropdown } from "@/components/UserDropdown"

export default function AppLayout() {
  const [scrolled, setScrolled] = useState(false)
  const [open, setOpen] = useState(true)
  const [hovered, setHovered] = useState(false)
  const { pathname } = useLocation()

  const isExpanded = open || hovered

  // Handle header background on scroll
  useEffect(() => {
    const onScroll = () => {
      setScrolled(window.scrollY > 10)
    }

    window.addEventListener("scroll", onScroll)
    return () => window.removeEventListener("scroll", onScroll)
  }, [])

  // Handle responsive sidebar
  useEffect(() => {
    const handleResize = () => {
      setOpen(window.innerWidth >= 1024)
    }

    handleResize()
    window.addEventListener("resize", handleResize)
    return () => window.removeEventListener("resize", handleResize)
  }, [])


  return (
    <SidebarProvider open={isExpanded} onOpenChange={setOpen}>
      <AppSidebar onHoverChange={setHovered} />

      <SidebarInset className="flex min-h-svh flex-col">
        {/* HEADER */}
        <header
                className={cn(
                    "px-6 sticky top-0 z-40 flex h-16 w-full shrink-0 items-center gap-2 transition-all duration-200 border-b bg-background",
                    scrolled
                    ? "bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60 shadow-md"
                    : "border-transparent"
                )}
              >
              <div className="flex items-center gap-1">
                <SidebarTrigger
                  className="-ml-1 rounded-full h-10 w-10 [&_svg]:!size-5 hover:bg-muted/60 transition-colors"
                />
                <div className="header-quick-link hidden md:flex items-center gap-1">
                  <a
                    href="/"
                    className="font-medium text-sm h-8 px-3 inline-flex items-center rounded-lg hover:bg-muted/60"
                  >
                    View website
                  </a>
                </div>
              </div>

                <div className="ml-auto">
                    <div className="flex items-center gap-1">
                        <GlobalSearch />
                        <AiAssistant />
                        <ThemeToggle />
                        <div className="relative inline-flex">
                            <NotificationDropdown />
                        </div>
                         <UserDropdown />
                    </div>
                </div>
              </header>

        {/* PAGE CONTENT */}
        <main className="flex-1 p-6">
          <div key={pathname} className="animate-fade-in">
            <Outlet />
          </div>
        </main>

        <Footer />
      </SidebarInset>
    </SidebarProvider>
  )
}
