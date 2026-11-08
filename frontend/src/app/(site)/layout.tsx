import { Suspense } from "react";

import Breadcrumbs from "@/components/breadcrumbs/Breadcrumbs";
import SiteFooter from "@/components/footer/SiteFooter";

export default function SiteLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <>
      <div className="site-shell">
        <Suspense fallback={null}>
          <Breadcrumbs />
        </Suspense>
      </div>
      <div id="main-content" tabIndex={-1} data-site-content-root>
        <div className="site-shell">{children}</div>
      </div>
      <SiteFooter />
    </>
  );
}
