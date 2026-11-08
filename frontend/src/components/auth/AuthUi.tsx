import Button, { ButtonLink } from "../button/Button";
import { InputHTMLAttributes, ReactNode, forwardRef, useEffect, useRef, useState } from "react";

type FloatingInputProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  rightSlot?: ReactNode;
};

export const FloatingInput = forwardRef<HTMLInputElement, FloatingInputProps>(function FloatingInput(
  { id, label, className = "", rightSlot, ...props },
  ref,
) {
  return (
    <div className="relative">
      <input
        {...props}
        ref={ref}
        id={id}
        placeholder={label}
        className={`peer auth-floating-input ui-input h-14 w-full rounded-[var(--radius-control)] border px-5 text-base font-medium outline-none placeholder:text-sm ${rightSlot ? "pr-14" : ""} ${className}`}
      />
      <label
        htmlFor={id}
        className="ui-input-label pointer-events-none absolute left-5 top-0 -translate-y-1/2 px-1 text-xs opacity-0 transition-opacity peer-focus:opacity-100 peer-not-placeholder-shown:opacity-100"
      >
        {label}
      </label>
      {rightSlot ? (
        <div className="absolute right-4 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center">
          {rightSlot}
        </div>
      ) : null}
    </div>
  );
});

export function restoreInputCaretToEnd(input: HTMLInputElement | null): void {
  window.requestAnimationFrame(() => {
    if (!input) {
      return;
    }

    input.focus();
    const caretPosition = input.value.length;
    input.setSelectionRange(caretPosition, caretPosition);
  });
}

type PasswordInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, "id" | "type"> & {
  id: string;
  label: string;
  shown: boolean;
  toggleDataUi: string;
  onToggleShown: () => void;
};

function PasswordVisibilityIcon({ shown }: { shown: boolean }) {
  return (
    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2">
      {shown ? (
        <>
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" />
          <circle cx="12" cy="12" r="3" />
        </>
      ) : (
        <>
          <path d="M3 3l18 18" />
          <path d="M10.6 10.6a2 2 0 102.8 2.8" />
          <path d="M9.9 4.2A10.8 10.8 0 0112 4c5.3 0 9.2 3.4 10.7 8-0.5 1.5-1.4 2.9-2.6 4" />
          <path d="M6.5 6.5C4.8 7.9 3.5 9.8 3 12c1.5 4.6 5.4 8 10.7 8 2 0 3.8-0.5 5.3-1.4" />
        </>
      )}
    </svg>
  );
}

export function PasswordInput({
  id,
  shown,
  toggleDataUi,
  onToggleShown,
  className = "",
  ...props
}: PasswordInputProps) {
  const inputRef = useRef<HTMLInputElement>(null);

  function onToggleClick(): void {
    onToggleShown();
    restoreInputCaretToEnd(inputRef.current);
  }

  return (
    <FloatingInput
      {...props}
      ref={inputRef}
      id={id}
      type={shown ? "text" : "password"}
      data-state={shown ? "shown" : "hidden"}
      className={`auth-password-input tracking-normal text-base ${className}`}
      rightSlot={
        <Button size="sm" fullWidth={false}
          type="button"
          data-ui={toggleDataUi}
          aria-label={shown ? "Скрыть пароль" : "Показать пароль"}
          aria-controls={id}
          aria-pressed={shown}
          onClick={onToggleClick}
          variant="ghost" iconOnly
        >
          <PasswordVisibilityIcon shown={shown} />
        </Button>
      }
    />
  );
}

export function VerifiedCheckIcon() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const rafId = window.requestAnimationFrame(() => setVisible(true));
    return () => window.cancelAnimationFrame(rafId);
  }, []);

  return (
    <span
      className={`auth-success-icon inline-flex h-7 w-7 items-center justify-center transition-all duration-300 ${
        visible ? "scale-100 opacity-100" : "scale-75 opacity-0"
      }`}
      aria-hidden="true"
    >
      <svg viewBox="0 0 20 20" fill="none" className="h-6 w-6">
        <path
          d="M5 10.5l3.1 3.1L15 6.7"
          stroke="currentColor"
          strokeWidth="2.4"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeDasharray="20"
          strokeDashoffset="20"
        >
          {visible ? (
            <animate attributeName="stroke-dashoffset" from="20" to="0" dur="520ms" begin="0s" fill="freeze" />
          ) : null}
        </path>
      </svg>
    </span>
  );
}

type HintActionProps = {
  label: string;
  disabled?: boolean;
  onClick: () => void;
  className?: string;
  title?: string;
  ariaLabel?: string;
  dataUi?: string;
};

export const HintAction = forwardRef<HTMLButtonElement, HintActionProps>(function HintAction(
  { label, disabled = false, onClick, className = "", title, ariaLabel, dataUi },
  ref,
) {
  return (
    <Button size="sm" fullWidth={false}
      ref={ref}
      type="button"
      data-ui={dataUi}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={ariaLabel}
      className={className}
    >
      {label}
    </Button>
  );
});

type PhoneCallPromptProps = {
  callNumber: string;
  callHref?: string;
  dataUi?: string;
};

function formatRuPhoneDisplay(value: string): string {
  const digits = value.replace(/\D/g, "");
  if (digits.length !== 11) {
    return value;
  }
  const normalized = digits.startsWith("8") ? `7${digits.slice(1)}` : digits;
  if (!normalized.startsWith("7")) {
    return value;
  }
  return `+7 (${normalized.slice(1, 4)}) ${normalized.slice(4, 7)}-${normalized.slice(7, 9)}-${normalized.slice(9, 11)}`;
}

export function PhoneCallPrompt({ callNumber, callHref, dataUi }: PhoneCallPromptProps) {
  if (!callNumber) {
    return null;
  }
  const displayCallNumber = formatRuPhoneDisplay(callNumber);

  return (
    <div className="mt-3 w-full px-6 text-sm font-normal ui-text-tertiary" data-ui={dataUi}>
      <div className="sm:hidden">
          <ButtonLink size="sm" fullWidth={false} disabled={!callHref}
            href={callHref}
            data-ui={dataUi ? `${dataUi}.callLink` : undefined}
          >
            позвонить
          </ButtonLink>
      </div>
      <p className="hidden text-lg font-semibold ui-text-soft-ink sm:block">
        {displayCallNumber}
      </p>
    </div>
  );
}
