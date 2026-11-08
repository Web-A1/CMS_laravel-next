"use client";

import type { ButtonHTMLAttributes, ReactNode } from "react";
import Button from "@/components/button/Button";
import { useContactModal } from "@/components/contact/ContactModalProvider";

type ContactModalTriggerProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  readonly children: ReactNode;
  readonly fullWidth?: boolean;
  readonly variant?: "primary" | "accent";
};

export default function ContactModalTrigger({
  children,
  className = "",
  fullWidth,
  onClick,
  variant,
  ...props
}: ContactModalTriggerProps) {
  const { openModal } = useContactModal();

  return (
    <Button
      {...props}
      type="button"
      className={className}
      fullWidth={fullWidth}
      variant={variant}
      onClick={(event) => {
        onClick?.(event);
        if (!event.defaultPrevented) {
          openModal(event.currentTarget);
        }
      }}
    >
      {children}
    </Button>
  );
}
