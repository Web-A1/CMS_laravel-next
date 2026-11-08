import type { Metadata } from "next";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import { Suspense } from "react";
import SiteHeader from "@/components/header/SiteHeader";
import SkipLink from "@/components/navigation/SkipLink";
import ScrollTopButton from "@/components/scroll-top/ScrollTopButton";
import { loadHeaderAccountProfile } from "@/lib/accountAuth";
import "./globals.css";

export const metadata: Metadata = {
  ...(!isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL)
    ? { robots: { index: false, follow: false } } : {}),
  title: {
    default: "Christmedschool",
    template: "%s | Christmedschool",
  },
  description:
    "Онлайн-школа для абитуриентов и студентов медицинских вузов: структурированные курсы, понятные объяснения и личный кабинет для обучения.",
  applicationName: "Christmedschool",
  formatDetection: {
    address: false,
    date: false,
    email: false,
    telephone: false,
    url: false,
  },
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const initialAccount = await loadHeaderAccountProfile();

  return (
    <html lang="ru" className="h-full antialiased">
      <head>
        <link rel="preload" href="/fonts/v2/montserrat/Montserrat-500-700-cyrillic.woff2" as="font" type="font/woff2" crossOrigin="anonymous" />
        <link rel="preload" href="/fonts/v2/montserrat/Montserrat-500-700-latin.woff2" as="font" type="font/woff2" crossOrigin="anonymous" />
      </head>
      <body className="min-h-full">
        <SkipLink />
        <Suspense fallback={null}><SiteHeader initialAccount={initialAccount} /></Suspense>
        <div className="header-spacer" aria-hidden="true" />
        {children}
        <p className="px-4 py-2 text-center text-sm ui-text-secondary" data-ui="demo.notice">
          Демонстрационная копия с публичными материалами школы. Записи и рабочие интеграции отключены.
        </p>
        <ScrollTopButton />
      </body>
    </html>
  );
}
