import { createBrowserRouter } from "react-router-dom"

import PublicLayout from "@/layouts/PublicLayout"
import AppLayout from "@/layouts/AppLayout"
import RequireAuth from "@/auth/RequireAuth"
import ErrorPage from "@/pages/ErrorPage"

// Public corporate site
import Home from "@/pages/public/Home"
import About from "@/pages/public/About"
import Services from "@/pages/public/Services"
import ServiceDetail from "@/pages/public/ServiceDetail"
import Sectors from "@/pages/public/Sectors"
import Careers from "@/pages/public/Careers"
import Accreditations from "@/pages/public/Accreditations"
import Resources from "@/pages/public/Resources"
import Article from "@/pages/public/Article"
import Contact from "@/pages/public/Contact"
import Quote from "@/pages/public/Quote"
import Verify from "@/pages/public/Verify"
import Legal from "@/pages/public/Legal"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

// Auth
import Login from "@/pages/auth/Login"

// CMS
import Dashboard from "@/pages/app/Dashboard"
import Clients from "@/pages/app/Clients"
import ClientDetail from "@/pages/app/ClientDetail"
import Enquiries from "@/pages/app/Enquiries"
import Comms from "@/pages/app/Comms"
import Workers from "@/pages/app/Workers"
import Compliance from "@/pages/app/Compliance"
import Scheduling from "@/pages/app/Scheduling"
import Jobs from "@/pages/app/Jobs"
import Quotes from "@/pages/app/Quotes"
import Contracts from "@/pages/app/Contracts"
import Timetable from "@/pages/app/Timetable"
import Invoicing from "@/pages/app/Invoicing"
import Academy from "@/pages/app/Academy"
import AcademyDetail from "@/pages/app/AcademyDetail"
import Analytics from "@/pages/app/Analytics"
import Files from "@/pages/app/Files"
import Settings from "@/pages/app/Settings"
import SmokeTest from "@/pages/app/SmokeTest"

export const router = createBrowserRouter([
  // 🌐 PUBLIC CORPORATE SITE
  {
    element: <PublicLayout />,
    errorElement: <ErrorPage />,
    children: [
      { index: true, element: <Home /> },
      { path: "about", element: <About /> },
      { path: "services", element: <Services /> },
      { path: "services/:slug", element: <ServiceDetail /> },
      { path: "sectors", element: <Sectors /> },
      { path: "careers", element: <Careers /> },
      { path: "accreditations", element: <Accreditations /> },
      { path: "resources", element: <Resources /> },
      { path: "resources/:slug", element: <Article /> },
      { path: "contact", element: <Contact /> },
      { path: "quote", element: <Quote /> },
      { path: "verify", element: <Verify /> },
      { path: "verify/:id", element: <Verify /> },
      { path: "privacy", element: <Legal kind="privacy" /> },
      { path: "terms", element: <Legal kind="terms" /> },
      { path: "cookies", element: <Legal kind="cookies" /> },
      { path: "*", element: <NotFoundPublic /> },
    ],
  },

  // 🔐 AUTH
  { path: "/login", element: <Login />, errorElement: <ErrorPage /> },

  // 📊 INTERNAL CMS (behind login)
  {
    path: "/app",
    element: <RequireAuth />,
    errorElement: <ErrorPage />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <Dashboard /> },
          { path: "clients", element: <Clients /> },
          { path: "clients/:id", element: <ClientDetail /> },
          { path: "enquiries", element: <Enquiries /> },
          { path: "workers", element: <Workers /> },
          { path: "scheduling", element: <Scheduling /> },
          { path: "timetable", element: <Timetable /> },
          { path: "jobs", element: <Jobs /> },
          { path: "compliance", element: <Compliance /> },
          { path: "quotes", element: <Quotes /> },
          { path: "contracts", element: <Contracts /> },
          { path: "invoicing", element: <Invoicing /> },
          { path: "academy", element: <Academy /> },
          { path: "academy/:id", element: <AcademyDetail /> },
          { path: "analytics", element: <Analytics /> },
          { path: "files", element: <Files /> },
          { path: "comms", element: <Comms /> },
          { path: "settings", element: <Settings /> },
          { path: "smoke-test", element: <SmokeTest /> },
        ],
      },
    ],
  },
])
