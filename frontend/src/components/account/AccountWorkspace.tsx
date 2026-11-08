"use client";

import { useEffect, useSyncExternalStore } from "react";
import type { ReactNode } from "react";
import { ButtonRouteLink } from "@/components/button/Button";
import { AdminPageHeader } from "@/components/admin/AdminPageHeader";
import workspaceStyles from "@/components/admin/AdminWorkspace.module.css";

const sections = [
  { id: "profile", title: "Профиль" },
  { id: "security", title: "Безопасность" },
  { id: "orders", title: "Покупки" },
  { id: "benefits", title: "Монетки и промокоды" },
  { id: "support", title: "Поддержка" },
] as const;

type SectionId = (typeof sections)[number]["id"];

function subscribe(onChange: () => void): () => void {
  window.addEventListener("hashchange", onChange);
  return () => window.removeEventListener("hashchange", onChange);
}

function getHash(): string {
  return window.location.hash.slice(1);
}

function getServerHash(): string {
  return "profile";
}

export default function AccountWorkspace({ content }: {
  readonly content: Readonly<Record<SectionId, ReactNode>>;
}) {
  const hash = useSyncExternalStore(subscribe, getHash, getServerHash);
  // Existing links from protected lessons must still reveal the browser panel.
  const activeId = hash === "trusted-browsers"
    ? "security"
    : sections.find(section => section.id === hash)?.id ?? "profile";

  useEffect(() => {
    if (hash === "trusted-browsers") {
      document.getElementById("trusted-browsers")?.scrollIntoView();
    }
  }, [hash]);

  return (
    <div className="grid min-w-0 gap-4 lg:grid-cols-[max-content_minmax(0,1fr)] lg:gap-8" data-ui="lk.workspace">
      <aside className="min-w-0 border-b border-[var(--color-border-subtle)] pb-4 lg:sticky lg:top-20 lg:self-start lg:border-0 lg:pb-0">
        <label className="sr-only" htmlFor="lk-mobile-navigation">Раздел личного кабинета</label>
        <select
          id="lk-mobile-navigation"
          value={activeId}
          onChange={event => { window.location.hash = event.target.value; }}
          className="ui-input h-11 w-full rounded-[var(--radius-control)] border px-3 text-sm lg:hidden"
          data-ui="lk.nav.mobileSelect"
        >
          {sections.map(section => <option key={section.id} value={section.id}>{section.title}</option>)}
        </select>
        <nav className={workspaceStyles.desktopNav} aria-label="Разделы личного кабинета" data-ui="lk.nav">
          <ul className="grid gap-1 p-0">
            {sections.map(section => (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  aria-current={activeId === section.id ? "location" : undefined}
                  className={workspaceStyles.navLink}
                  data-label={section.title}
                  data-state={activeId === section.id ? "active" : "idle"}
                  data-ui={`lk.nav.${section.id}`}
                >
                  <span className={workspaceStyles.navLinkLabel}>{section.title}</span>
                </a>
              </li>
            ))}
          </ul>
        </nav>
        <ButtonRouteLink
          href="/lk/learning"
          className={`mt-4 ${workspaceStyles.sidebarAction}`} fullWidth={false}
          data-ui="lk.learningLink"
        >
          Перейти в обучение
        </ButtonRouteLink>
        <ButtonRouteLink href="/lk/guides" variant="secondary" className={`mt-3 ${workspaceStyles.sidebarAction}`} fullWidth={false} data-ui="lk.guidesLink">Мои методички</ButtonRouteLink>
      </aside>
      <div className="min-w-0 max-w-3xl" data-ui="lk.workspace.content">
        <div id="lk-dashboard-title" tabIndex={-1} className="mb-7 outline-none">
          <AdminPageHeader title={sections.find(section => section.id === activeId)?.title ?? "Профиль"} id="lk-section-title" />
        </div>
        <p className="sr-only" role="status">Открыт раздел: {sections.find(section => section.id === activeId)?.title}</p>
        {sections.map(section => (
          <div key={section.id} hidden={activeId !== section.id} data-ui={`lk.panel.${section.id}`}>
            {content[section.id]}
          </div>
        ))}
      </div>
    </div>
  );
}
