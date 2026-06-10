import { Link } from "react-router-dom"
import { ArrowUp, Linkedin, Facebook, Mail, Phone } from "lucide-react"
import { Logo } from "@/components/brand/Logo"
import { DIVISIONS } from "@/lib/divisions"

export function PublicFooter() {
  return (
    <footer className="mt-20 border-t border-border bg-secondary/40">
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <Logo />
          <p className="mt-3 max-w-xs text-sm text-muted-foreground">
            Care, training, cleaning and security across London. Trained, checked and trusted staff,
            from one name.
          </p>
          <div className="mt-4 space-y-1 text-sm text-muted-foreground">
            <a href="tel:+442000000000" className="flex items-center gap-2 hover:text-foreground">
              <Phone className="size-4" /> 020 0000 0000
            </a>
            <a
              href="mailto:hello@ritagreenservices.com"
              className="flex items-center gap-2 hover:text-foreground"
            >
              <Mail className="size-4" /> hello@ritagreenservices.com
            </a>
          </div>
        </div>

        <div>
          <h3 className="text-sm font-semibold text-foreground">Company</h3>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><Link to="/about" className="hover:text-foreground">About</Link></li>
            <li><Link to="/sectors" className="hover:text-foreground">Sectors we serve</Link></li>
            <li><Link to="/accreditations" className="hover:text-foreground">Accreditations</Link></li>
            <li><Link to="/resources" className="hover:text-foreground">Resources</Link></li>
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
            © {new Date().getFullYear()} RitaGreen Healthcare Services Ltd. Company No. 12528439.
          </p>
          <div className="flex items-center gap-4">
            <a href="#" aria-label="LinkedIn" className="hover:text-foreground"><Linkedin className="size-4" /></a>
            <a href="#" aria-label="Facebook" className="hover:text-foreground"><Facebook className="size-4" /></a>
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
