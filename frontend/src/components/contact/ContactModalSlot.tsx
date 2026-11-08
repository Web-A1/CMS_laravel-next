"use client";

import ContactModal from "@/components/contact/ContactModal";
import { useContactModal } from "@/components/contact/ContactModalProvider";

type ContactChannel = "telegram" | "max";

type ContactLink = {
  readonly href: string;
  readonly label: string;
  readonly channel: ContactChannel;
};

type ContactModalSlotProps = {
  readonly channels?: ReadonlyArray<ContactLink>;
  readonly dataUiPrefix?: string;
  readonly title?: string;
  readonly description?: string;
};

export default function ContactModalSlot(props: ContactModalSlotProps) {
  const { open } = useContactModal();

  return open ? <ContactModal {...props} /> : null;
}
