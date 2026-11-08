import type { Metadata } from "next";

import AuthPageClient from "./AuthPageClient";

export const metadata: Metadata = {
  title: "Вход и регистрация",
  description: "Вход, регистрация и восстановление доступа к личному кабинету Christmedschool.",
  robots: {
    index: false,
    follow: false,
  },
};

export default function AuthPage() {
  return (
    <div className="auth-page-metadata" data-ui="auth.metadataBoundary">
      <AuthPageClient />
    </div>
  );
}
