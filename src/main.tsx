import React from "react"
import ReactDOM from "react-dom/client"
import { RouterProvider } from "react-router-dom"
import { ThemeProvider } from "next-themes"
import { PwaStatus } from "@/components/pwa-status"
import { Toaster } from "sonner"

import { AuthProvider } from "@/providers/auth-provider"

import { router } from "@/routes"
import "@/index.css"

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <ThemeProvider
      attribute="class"
      defaultTheme="light"
      enableSystem
      disableTransitionOnChange
    >
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </ThemeProvider>
    <Toaster position="top-right" richColors closeButton />
    <PwaStatus />
  </React.StrictMode>
)

// Service worker registration lives in <PwaStatus/>, which also owns the
// update prompt and the offline banner. Registering here as well would race it.
