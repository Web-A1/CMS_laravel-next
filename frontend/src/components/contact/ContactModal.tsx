"use client";

import { useEffect, useRef } from "react";
import ContactChannelButton from "@/components/button/ContactChannelButton";
import CloseButton from "@/components/button/CloseButton";
import { useContactModal } from "@/components/contact/ContactModalProvider";
import { SCHOOL_TELEGRAM_URL } from "@/lib/contactLinks";

type ContactChannel = "telegram" | "max";

type ContactLink = {
  readonly href: string;
  readonly label: string;
  readonly channel: ContactChannel;
};

type ContactModalProps = {
  readonly channels?: ReadonlyArray<ContactLink>;
  readonly dataUiPrefix?: string;
  readonly description?: string;
  readonly title?: string;
};

const defaultContactLinks: ReadonlyArray<ContactLink> = [
  { href: SCHOOL_TELEGRAM_URL, label: "Telegram", channel: "telegram" },
  { href: "#", label: "MAX", channel: "max" },
];

function getFocusableElements(root: HTMLElement | null): HTMLElement[] {
  if (!root) return [];
  return Array.from(root.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'));
}

export default function ContactModal({
  channels = defaultContactLinks,
  dataUiPrefix = "contact.modal",
  description = "Выберите удобный способ и мы отправим бесплатный урок.",
  title = "Свяжитесь с нами",
}: ContactModalProps) {
  const { closeModal, open } = useContactModal();
  const modalRef = useRef<HTMLDivElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    document.body.classList.toggle("menu-scroll-lock", open);
    return () => document.body.classList.remove("menu-scroll-lock");
  }, [open]);

  useEffect(() => {
    if (!open) return;

    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === "Escape") {
        closeModal();
        return;
      }

      if (event.key !== "Tab") return;
      const focusables = getFocusableElements(panelRef.current);
      if (!focusables.length) return;

      // Safari may skip links during native Tab navigation. Keep every control
      // reachable and contain focus regardless of the browser's keyboard setting.
      const activeIndex = focusables.findIndex((element) => element === document.activeElement);
      const nextIndex = activeIndex < 0
        ? (event.shiftKey ? focusables.length - 1 : 0)
        : (activeIndex + (event.shiftKey ? -1 : 1) + focusables.length) % focusables.length;
      event.preventDefault();
      focusables[nextIndex].focus();
    }

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [closeModal, open]);

  useEffect(() => {
    if (!open) return;

    const firstFocusable = getFocusableElements(panelRef.current)[0];
    firstFocusable?.focus();
  }, [open]);

  return (
    <div
      ref={modalRef}
      className={`contact-modal ${open ? "is-open" : ""}`}
      aria-hidden={!open}
      data-ui={dataUiPrefix}
      onMouseDown={(event) => {
        if (event.target !== modalRef.current) return;
        // Keep the pointer's default focus change from overriding restoration.
        event.preventDefault();
        closeModal();
      }}
    >
      <div
        ref={panelRef}
        className="contact-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-label="Способы связи"
        data-ui={`${dataUiPrefix}.panel`}
      >
        <div className="contact-modal__content">
          <div className="contact-modal__header">
            <h2 className="contact-modal__title">{title}</h2>
            <CloseButton
              className="contact-modal__close"
              aria-label="Закрыть модальное окно"
              data-ui={`${dataUiPrefix}.close`}
              onClick={closeModal}
            />
          </div>
          <p className="contact-modal__text">{description}</p>

          <div className="contact-modal__links">
            {channels.map((link) => (
              <ContactChannelButton
                key={link.label}
                href={link.href}
                className="contact-modal__channel"
                channel={link.channel}
                label={link.label}
                aria-label={`Написать в ${link.label}`}
                data-ui={`${dataUiPrefix}.${link.channel}Link`}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
