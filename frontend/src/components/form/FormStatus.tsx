import { HTMLAttributes, ReactNode } from "react";

type FormStatusTone = "neutral" | "pending" | "success";

type FormStatusProps = HTMLAttributes<HTMLParagraphElement> & {
  children?: ReactNode;
  dataUi?: string;
  tone?: FormStatusTone;
  inset?: boolean;
};

const toneClassName: Record<FormStatusTone, string> = {
  neutral: "ui-text-secondary",
  pending: "ui-text-secondary",
  success: "ui-text-tertiary",
};

export function FormStatus({
  children,
  className = "",
  dataUi,
  role = "status",
  tone = "neutral",
  inset = true,
  ...props
}: FormStatusProps) {
  if (!children) {
    return null;
  }

  return (
    <p
      {...props}
      role={role}
      aria-live={props["aria-live"] ?? "polite"}
      data-ui={dataUi}
      data-status={tone}
      className={[inset ? "px-6" : "px-0", "text-left text-sm font-medium", toneClassName[tone], className].filter(Boolean).join(" ")}
    >
      {children}
    </p>
  );
}
