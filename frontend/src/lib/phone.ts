export function isPhoneCandidate(value: string): boolean {
  return !value.includes("@") && /^[\d+()\-\s]*$/.test(value);
}

export function formatRuPhoneInput(value: string): string {
  const raw = value.replace(/\D/g, "");
  if (!raw) return "";

  let digits = raw;
  if (digits.startsWith("8")) {
    digits = `7${digits.slice(1)}`;
  } else if (!digits.startsWith("7")) {
    digits = `7${digits}`;
  }

  digits = digits.slice(0, 11);

  const c = digits.slice(1, 4);
  const p1 = digits.slice(4, 7);
  const p2 = digits.slice(7, 9);
  const p3 = digits.slice(9, 11);

  let out = "+7";
  if (c) out += ` (${c}`;
  if (c.length === 3) out += ")";
  if (p1) out += ` ${p1}`;
  if (p2) out += `-${p2}`;
  if (p3) out += `-${p3}`;

  return out;
}

export function applyRuPhoneMask(prev: string, next: string): string {
  const prevDigits = prev.replace(/\D/g, "");
  const nextDigits = next.replace(/\D/g, "");

  // User removed only a formatting char (digits count unchanged).
  // In this case also remove one digit to make backspace behavior natural.
  if (next.length < prev.length && nextDigits.length === prevDigits.length) {
    const sliced = prevDigits.slice(0, -1);
    return formatRuPhoneInput(sliced);
  }

  return formatRuPhoneInput(next);
}

export function isCompleteRuPhone(value: string): boolean {
  const raw = value.replace(/\D/g, "");
  if (raw.length !== 11) return false;

  if (raw.startsWith("7")) return true;
  if (raw.startsWith("8")) return true;

  return false;
}
