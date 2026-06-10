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
import Accreditations from "@/pages/public/Accreditations"
import Resources from "@/pages/public/Resources"
import Article from "@/pages/public/Article"
import Contact from "@/pages/public/Contact"
import Verify from "@/pages/public/Verify"
import Legal from "@/pages/public/Legal"
import NotFoundPublic from "@/pages/public/NotFoundPublic"

// Auth
import Login from "@/pages/auth/Login"

// CMS
import Dashboard from "@/pages/app/Dashboard"
import Clients from "@/pages/app/Clients"
import ClientDetail from "@/pages/app/ClientDetail"
import Workers from "@/pages/app/Workers"
import Compliance from "@/pages/app/Compliance"
import Scheduling from "@/pages/app/Scheduling"
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
      { path: "accreditations", element: <Accreditations /> },
      { path: "resources", element: <Resources /> },
      { path: "resources/:slug", element: <Article /> },
      { path: "contact", element: <Contact /> },
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
          { path: "workers", element: <Workers /> },
          { path: "scheduling", element: <Scheduling /> },
          { path: "compliance", element: <Compliance /> },
          { path: "smoke-test", element: <SmokeTest /> },
        ],
      },
    ],
  },
])
