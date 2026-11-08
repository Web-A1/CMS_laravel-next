"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";

import { buildBreadcrumbs } from "./breadcrumbLabels";
import BreadcrumbTrail from "./BreadcrumbTrail";
import { loadLearningBreadcrumbLabelOverrides, type LearningBreadcrumbLabelOverrides } from "@/lib/clientLearningBreadcrumbs";

const authModeLabels: Record<string, string> = {
  login: "Вход",
  register: "Регистрация",
  forgot: "Сброс пароля",
  reset: "Новый пароль",
  "verify-phone": "Подтверждение телефона",
  verify: "Подтверждение профиля",
};

export default function Breadcrumbs() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [learningLabels, setLearningLabels] = useState<{
    readonly labels: LearningBreadcrumbLabelOverrides;
    readonly path: string;
  }>({ labels: {}, path: "" });

  const cleanPath = pathname.replace(/\/+$/, "") || "/";

  useEffect(() => {
    const currentParts = cleanPath.split("/").filter(Boolean);
    if (currentParts[0] !== "lk" || currentParts[1] !== "learning") {
      return;
    }

    const controller = new AbortController();
    void loadLearningBreadcrumbLabelOverrides(currentParts, { signal: controller.signal }).then((labels) => {
      if (!controller.signal.aborted) {
        setLearningLabels({ labels, path: cleanPath });
      }
    });

    return () => controller.abort();
  }, [cleanPath]);

  if (cleanPath === "/") {
    return null;
  }

  const parts = cleanPath.split("/").filter(Boolean);
  // Article detail renders the trail on the server from its public DTO.
  if (parts.length === 2 && parts[0] === "articles") return null;

  const labelOverrides = learningLabels.path === cleanPath ? learningLabels.labels : {};
  const crumbs = buildBreadcrumbs(parts).map((crumb) => {
    const label = labelOverrides[crumb.href];
    return label ? { ...crumb, label } : crumb;
  });

  if (parts[0] === "auth") {
    const mode = searchParams.get("mode") ?? "login";
    const modeLabel = authModeLabels[mode];
    if (modeLabel) {
      crumbs.push({
        href: `${cleanPath}?mode=${mode}`,
        label: modeLabel,
      });
    }
  }

  return <BreadcrumbTrail items={crumbs} />;
}
