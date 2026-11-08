"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import type { ReactNode } from "react";

type ContactModalContextValue = {
  readonly open: boolean;
  readonly closeModal: () => void;
  readonly openModal: (trigger: HTMLButtonElement) => void;
};

const ContactModalContext = createContext<ContactModalContextValue | null>(null);

export function useContactModal(): ContactModalContextValue {
  const context = useContext(ContactModalContext);
  if (!context) {
    throw new Error("Contact modal components must be used inside ContactModalProvider");
  }
  return context;
}

export default function ContactModalProvider({ children }: { readonly children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const triggerButtonRef = useRef<HTMLButtonElement | null>(null);
  const wasOpenRef = useRef(false);

  const openModal = useCallback((trigger: HTMLButtonElement) => {
    triggerButtonRef.current = trigger;
    setOpen(true);
  }, []);

  const closeModal = useCallback(() => setOpen(false), []);

  useEffect(() => {
    if (open) {
      wasOpenRef.current = true;
      return;
    }

    if (!wasOpenRef.current) return;
    triggerButtonRef.current?.focus();
    triggerButtonRef.current = null;
    wasOpenRef.current = false;
  }, [open]);

  const value = useMemo(
    () => ({ open, closeModal, openModal }),
    [closeModal, open, openModal],
  );

  return (
    <ContactModalContext.Provider value={value}>{children}</ContactModalContext.Provider>
  );
}
