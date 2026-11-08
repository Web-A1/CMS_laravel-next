import type { Metadata } from "next";
import { redirect } from "next/navigation";

import AccountUnavailableState from "@/components/account/AccountUnavailableState";
import { accountApiBaseUrl, fetchAccountBackend, loadAccountProfile } from "@/lib/accountAuth";

export const metadata: Metadata = {
  title: "Переход в кабинет",
  description: "Проверка авторизации и переход в личный кабинет Christmedschool.",
  robots: {
    index: false,
    follow: false,
  },
};

type AdminStatus = "authorized" | "not-authorized";

async function loadAdminStatus(apiBaseUrl: string): Promise<AdminStatus> {
  let response: Response;
  try {
    response = await fetchAccountBackend(apiBaseUrl, "/api/v1/admin/me", "/account");
  } catch {
    return "not-authorized";
  }

  return response.ok ? "authorized" : "not-authorized";
}

export default async function AccountEntryPage() {
  const apiBaseUrl = accountApiBaseUrl();
  const account = await loadAccountProfile(apiBaseUrl, "/account");

  if (account.status === "unauthenticated") {
    redirect("/auth?mode=login&next=/lk/learning");
  }
  if (account.status === "unavailable") {
    return <AccountUnavailableState retryHref="/account" />;
  }

  const adminStatus = await loadAdminStatus(apiBaseUrl);
  if (adminStatus === "authorized") {
    redirect("/admin");
  }

  redirect("/lk/learning");
}
