import { HTMLAttributes, ReactNode } from "react";

type FormErrorProps = HTMLAttributes<HTMLParagraphElement> & {
  children?: ReactNode;
  dataUi?: string;
  inset?: boolean;
};

export function FormError({ children, className = "", dataUi, role = "alert", inset = true, ...props }: FormErrorProps) {
  if (!children) {
    return null;
  }

  return (
    <p
      {...props}
      role={role}
      data-ui={dataUi}
      className={[inset ? "px-6" : "px-0", "text-left text-sm font-medium ui-text-danger", className].filter(Boolean).join(" ")}
    >
      {children}
    </p>
  );
}
