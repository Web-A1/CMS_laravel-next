import type { ReactNode } from "react";
import { headers } from "next/headers";
import { redirect } from "next/navigation";

import "@/components/learning/learning-layout.css";
import AccountUnavailableState from "@/components/account/AccountUnavailableState";
import { accountApiBaseUrl, loadAccountProfile } from "@/lib/accountAuth";
import { AUTH_RETURN_TO_HEADER, buildAuthPageHref, normalizeAuthNextPath } from "@/lib/authNavigation";

type LkLayoutProps = {
  readonly children: ReactNode;
};

export default async function LkLayout({ children }: LkLayoutProps) {
  const requestHeaders = await headers();
  const nextPath = normalizeAuthNextPath(requestHeaders.get(AUTH_RETURN_TO_HEADER));
  const apiBaseUrl = accountApiBaseUrl();
  const account = await loadAccountProfile(apiBaseUrl, "/lk");

  if (account.status === "unauthenticated") {
    redirect(buildAuthPageHref("login", nextPath));
  }

  if (account.status === "unavailable") {
    return <AccountUnavailableState retryHref="/lk" />;
  }

  const profile = account.profile;

  if (profile.impersonation?.status !== "active" && (profile.must_verify_phone ?? profile.phone_verified === false)) {
    redirect(buildAuthPageHref("verify-phone", nextPath));
  }

  return (
    <>
      {children}
    </>
  );
}
