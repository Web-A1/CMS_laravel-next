"use client";

import { useEffect, useId, useRef, useState } from "react";
import CloseButton from "@/components/button/CloseButton";

export default function CooperationModal() {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const descriptionId = useId();

  useEffect(() => {
    if (!open) return;
    const dialog = dialogRef.current;
    const trigger = triggerRef.current;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    dialog?.showModal();
    return () => {
      dialog?.close();
      document.body.style.overflow = previousOverflow;
      if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    };
  }, [open]);

  return <>
    <button ref={triggerRef} type="button" className="ui-text-link ui-text-link--muted ui-text-link--interactive min-h-5 text-sm" aria-haspopup="dialog" data-ui="site.footer.cooperation" onClick={() => setOpen(true)}>Сотрудничество</button>
    {open ? <dialog ref={dialogRef} aria-labelledby={titleId} aria-describedby={descriptionId} data-ui="cooperation.modal" data-lenis-prevent
      className="ui-text-primary m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-modal)] backdrop:bg-[var(--color-modal-overlay)] sm:p-6"
      onCancel={(event) => { event.preventDefault(); setOpen(false); }}
      onClick={(event) => {
        if (event.target !== event.currentTarget) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) setOpen(false);
      }}>
      <div className="mb-4 flex items-start justify-between gap-4">
        <h2 id={titleId} className="text-xl font-bold">Сотрудничество и реклама</h2>
        <CloseButton aria-label="Закрыть окно сотрудничества" data-ui="cooperation.close" onClick={() => setOpen(false)} />
      </div>
      <p id={descriptionId} className="ui-text-secondary text-sm leading-relaxed">
        По вопросам сотрудничества и рекламы пишите на электронную почту{" "}
        <a href="mailto:demo@example.test" className="link-text font-semibold" data-ui="cooperation.email">demo@example.test</a>
      </p>
    </dialog> : null}
  </>;
}
