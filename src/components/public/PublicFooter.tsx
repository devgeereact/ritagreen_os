import { useState, type ReactNode } from "react"
import { Link } from "react-router-dom"
import { ArrowUp, Instagram, Linkedin, Facebook, Mail, Phone } from "lucide-react"
import { toast } from "sonner"
import { Logo } from "@/components/brand/Logo"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { DIVISIONS } from "@/lib/divisions"
import { COMPANY, SOCIALS } from "@/lib/company"

// Compact, single-instance newsletter signup. Lives in the footer so the public
// site has exactly one subscribe field site-wide, not a band repeated per page.
function FooterSubscribe() {
  const [email, setEmail] = useState("")

  function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      toast.error("That email doesn't look right.")
      return
    }
    toast.success("Thanks — we'll keep you posted on RitaGreen news.")
    setEmail("")
  }

  return (
    <div className="border-b border-border">
      <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h3 className="font-display text-lg font-bold tracking-tight">Stay in the loop</h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Occasional updates on care, training, cleaning and security across London. No spam.
          </p>
        </div>
        <form onSubmit={onSubmit} className="flex w-full gap-2 sm:max-w-sm">
          <Input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="Enter your email"
            aria-label="Email address"
            className="bg-background"
          />
          <Button type="submit" className="shrink-0">
            Subscribe
          </Button>
        </form>
      </div>
    </div>
  )
}

const SOCIAL_ICON: Record<string, ReactNode> = {
  instagram: <Instagram className="size-4" />,
  facebook: <Facebook className="size-4" />,
  linkedin: <Linkedin className="size-4" />,
  x: <i className="bi bi-twitter-x text-[15px] leading-none" />,
}

export function PublicFooter() {
  return (
    <footer className="mt-20 border-t border-border bg-soft-mesh">
      <FooterSubscribe />
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <Logo />
          <p className="mt-3 text-xs font-semibold uppercase tracking-[0.14em] text-primary">
            {COMPANY.primaryTagline}
          </p>
          <p className="mt-3 max-w-xs text-sm text-muted-foreground">
            Care, training, cleaning and security across London. Trained, checked and trusted staff,
            from one name.
          </p>
          {/* For any enquiry — Oxybuild's prominent footer call-to-action. */}
          <div className="mt-5 rounded-2xl border border-border bg-card p-5 shadow-soft">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
              For any enquiry
            </p>
            <a
              href={COMPANY.phoneHref}
              className="mt-1 block font-display text-2xl font-bold tracking-tight text-primary hover:underline"
            >
              {COMPANY.phone}
            </a>
            <div className="mt-3 space-y-1.5 text-sm text-muted-foreground">
              <a href={COMPANY.emergencyPhoneHref} className="flex items-center gap-2 hover:text-foreground">
                <Phone className="size-4" /> {COMPANY.emergencyPhone}
                <span className="text-xs font-medium text-primary">24hr</span>
              </a>
              <a
                href={`mailto:${COMPANY.email}`}
                className="flex items-center gap-2 hover:text-foreground"
              >
                <Mail className="size-4" /> {COMPANY.email}
              </a>
            </div>
          </div>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Company</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><Link to="/about" className="hover:text-foreground">About</Link></li>
            <li><Link to="/sectors" className="hover:text-foreground">Sectors we serve</Link></li>
            <li><Link to="/accreditations" className="hover:text-foreground">Accreditations</Link></li>
            <li><Link to="/careers" className="hover:text-foreground">Careers</Link></li>
            <li><Link to="/resources" className="hover:text-foreground">Resources</Link></li>
            <li><Link to="/quote" className="hover:text-foreground">Request a quote</Link></li>
            <li><Link to="/contact" className="hover:text-foreground">Contact</Link></li>
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Services</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            {DIVISIONS.map((d) => (
              <li key={d.id}>
                <Link to={`/services#${d.id}`} className="flex items-center gap-2 hover:text-foreground">
                  <span className="size-2 rounded-full" style={{ backgroundColor: d.accent }} />
                  {d.name}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Resources & legal</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><Link to="/resources" className="hover:text-foreground">Guides & articles</Link></li>
            <li><Link to="/verify" className="hover:text-foreground">Verify a certificate</Link></li>
            <li><Link to="/privacy" className="hover:text-foreground">Privacy policy</Link></li>
            <li><Link to="/terms" className="hover:text-foreground">Terms</Link></li>
            <li><Link to="/cookies" className="hover:text-foreground">Cookies</Link></li>
            <li><Link to="/login" className="hover:text-foreground">Member login</Link></li>
          </ul>
        </div>
      </div>

      <div className="border-t border-border">
        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-5 text-xs text-muted-foreground sm:flex-row">
          <p>
            © {new Date().getFullYear()} {COMPANY.legalName}. Company No. {COMPANY.companyNo}.
          </p>
          <div className="flex items-center gap-4">
            {SOCIALS.map((s) => (
              <a
                key={s.key}
                href={s.href}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={s.label}
                className="hover:text-foreground"
              >
                {SOCIAL_ICON[s.key]}
              </a>
            ))}
            <button
              onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
              className="flex items-center gap-1 hover:text-foreground"
            >
              Back to top <ArrowUp className="size-3.5" />
            </button>
          </div>
        </div>
      </div>
    </footer>
  )
}
