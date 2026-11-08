import type { AnchorHTMLAttributes } from "react";
import { ButtonLink, type ButtonSize } from "./Button";

type ContactChannel = "telegram" | "max";

type ContactChannelButtonProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
  channel: ContactChannel;
  label: string;
  iconOnly?: boolean;
  size?: ButtonSize;
  surface?: "light" | "brand";
};

function TelegramIcon({ monochrome }: { monochrome: boolean }) {
  return (
    <svg className="contact-channel-button__icon" viewBox="20 28 80 76" aria-hidden="true" focusable="false">
      <path
        className="contact-channel-button__telegram-plane"
        style={monochrome ? { fill: "currentColor" } : undefined}
        d="M28.9700376 63.3244248C47.6273373 55.1957357 60.0684594 49.8368063 66.2934036 47.2476366 84.0668845 39.855031 87.7600616 38.5708563 90.1672227 38.528c.5294328-.0088742 1.7132047.1223351 2.4800024.7445385.6474689.5253764.8256136 1.2350852.9108614 1.7331996.085248.4981144.191402 1.6328319.1070176 2.5194671-.9631512 10.119913-5.1306908 34.6782731-7.2508984 46.012749-.8971395 4.7960416-2.6636347 6.4041299-4.3738066 6.5615026-3.7165995.3420073-6.5388164-2.4561883-10.1385248-4.8158425-5.6328329-3.6923931-8.8150251-5.9909191-14.2826648-9.5940126-6.3188037-4.1639979-2.2225863-6.4526036 1.3784816-10.1928256.9424165-.978834 17.3178391-15.8735468 17.634786-17.2247307.0396394-.1689881.0764268-.7988958-.2977904-1.1315102-.3742172-.3326144-.9265294-.2188732-1.3250938-.1284139-.5649523.1282234-9.5634758 6.0759138-26.9955708 17.8430709-2.554201 1.7539115-4.8677169 2.608476-6.9405477 2.5636937-2.2851298-.049369-6.6808026-1.2920471-9.9485335-2.3542577-4.008005-1.3028444-7.1934876-1.9916677-6.9161036-4.2043006.1444787-1.1524762 1.7315452-2.3311104 4.7611996-3.5359028Z"
      />
    </svg>
  );
}

function MaxIcon({ monochrome }: { monochrome: boolean }) {
  return (
    <svg className="contact-channel-button__icon" viewBox="100 100 800 800" aria-hidden="true" focusable="false">
      <path
        className="contact-channel-button__mark"
        style={monochrome ? { fill: "currentColor" } : undefined}
        fillRule="evenodd"
        clipRule="evenodd"
        d="M508.211 878.328c-75.007 0-109.864-10.95-170.453-54.75-38.325 49.275-159.686 87.783-164.979 21.9 0-49.456-10.95-91.248-23.36-136.873-14.782-56.21-31.572-118.807-31.572-209.508 0-216.626 177.754-379.597 388.357-379.597 210.785 0 375.947 171.001 375.947 381.604.707 207.346-166.595 376.118-373.94 377.224m3.103-571.585c-102.564-5.292-182.499 65.7-200.201 177.024-14.6 92.162 11.315 204.398 33.397 210.238 10.585 2.555 37.23-18.98 53.837-35.587a189.8 189.8 0 0 0 92.71 33.032c106.273 5.112 197.08-75.794 204.215-181.95 4.154-106.382-77.67-196.486-183.958-202.574Z"
      />
    </svg>
  );
}

export function ContactChannelIcon({ channel, monochrome = false }: { channel: ContactChannel; monochrome?: boolean }) {
  return channel === "telegram" ? <TelegramIcon monochrome={monochrome} /> : <MaxIcon monochrome={monochrome} />;
}

export default function ContactChannelButton({ channel, className = "", iconOnly = false, label, size = "md", surface = "light", ...props }: ContactChannelButtonProps) {
  return (
    <ButtonLink
      variant="white"
      size={size}
      fullWidth={false}
      iconOnly={iconOnly}
      data-surface={surface}
      {...props}
      className={["contact-channel-button", `contact-channel-button--${channel}`, iconOnly ? "contact-channel-button--icon-only" : "", className].filter(Boolean).join(" ")}
    >
      <span className="contact-channel-button__icon-slot"><ContactChannelIcon channel={channel} /></span>
      <span className={iconOnly ? "sr-only" : undefined}>{label}</span>
    </ButtonLink>
  );
}
