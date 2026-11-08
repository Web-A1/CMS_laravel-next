import type { ButtonHTMLAttributes } from "react";
import { forwardRef } from "react";
import Button from "./Button";

type CloseButtonProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, "children"> & {
  backdrop?: "none" | "translucent";
  label?: string;
};

const CloseButton = forwardRef<HTMLButtonElement, CloseButtonProps>(function CloseButton(
  { backdrop = "none", className = "", label = "Закрыть", type = "button", "aria-label": ariaLabel, ...props },
  ref,
) {
  return (
    <Button
      {...props}
      ref={ref}
      type={type}
      variant={backdrop === "translucent" ? "photo" : "ghost"}
      size="sm"
      iconOnly
      fullWidth={false}
      className={["close-button", className].filter(Boolean).join(" ")}
      aria-label={ariaLabel ?? label}
      data-backdrop={backdrop === "translucent" ? "translucent" : undefined}
    >
      <span className="close-button__glyph" aria-hidden="true">
        <span className="close-button__line close-button__line--first" />
        <span className="close-button__line close-button__line--second" />
      </span>
    </Button>
  );
});

export default CloseButton;
