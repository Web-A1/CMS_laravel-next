import type { CheckoutWidgetPayload } from "./checkoutContract.ts";

export type CheckoutPaymentMethod = "bank-card" | "sbp" | "mir-pay";

type WidgetOptions = {
  publicTerminalId: string;
  description: string;
  amount: number;
  currency: string;
  externalId: string;
  paymentSchema: "Single";
  restrictedPaymentMethods: string[];
  retryPayment: false;
  tokenize: false;
  culture: "ru-RU";
};

type CloudPaymentsWidget = {
  oncomplete?: (result: unknown) => void;
  start(options: WidgetOptions): Promise<unknown>;
};

declare global {
  interface Window {
    cp?: { CloudPayments: new () => CloudPaymentsWidget };
  }
}

const scriptId = "cloudpayments-widget-script";
let scriptPromise: Promise<void> | null = null;

export function cloudPaymentsOptions(payload: CheckoutWidgetPayload, method: CheckoutPaymentMethod): WidgetOptions {
  const methods = ["Card", "TcsInstallment", "Sbp", "TinkoffPay", "MirPay", "Dolyame", "ForeignCard", "SberPay"];
  const selectedMethod: Record<CheckoutPaymentMethod, string> = {
    "bank-card": "Card", sbp: "Sbp", "mir-pay": "MirPay",
  };
  return {
    publicTerminalId: payload.public_id,
    description: payload.description,
    amount: Number(payload.amount),
    currency: payload.currency,
    externalId: payload.invoice_id,
    paymentSchema: "Single",
    restrictedPaymentMethods: methods.filter((value) => value !== selectedMethod[method]),
    retryPayment: false,
    tokenize: false,
    culture: "ru-RU",
  };
}

export function loadCloudPaymentsScript(): Promise<void> {
  if (typeof window === "undefined") {
    return Promise.reject(new Error("Форма оплаты доступна только в браузере."));
  }
  if (window.cp?.CloudPayments) return Promise.resolve();
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise<void>((resolve, reject) => {
    document.getElementById(scriptId)?.remove();
    const script = document.createElement("script");
    script.id = scriptId;
    script.async = true;
    script.src = "https://widget.cloudpayments.ru/bundles/cloudpayments.js";
    const finish = (error?: Error) => {
      clearTimeout(timeout);
      script.onload = null;
      script.onerror = null;
      if (error) {
        script.remove();
        reject(error);
      } else {
        resolve();
      }
    };
    const timeout = setTimeout(() => finish(new Error("Форма оплаты долго не загружается. Попробуйте ещё раз.")), 15_000);
    script.onload = () => finish(window.cp?.CloudPayments ? undefined : new Error("Не удалось инициализировать форму оплаты."));
    script.onerror = () => finish(new Error("Не удалось загрузить форму оплаты. Попробуйте ещё раз."));
    document.head.appendChild(script);
  }).catch((error: unknown) => {
    scriptPromise = null;
    throw error;
  });
  return scriptPromise;
}

let widgetOpen = false;

export async function openCloudPaymentsWidget(
  payload: CheckoutWidgetPayload,
  method: CheckoutPaymentMethod,
  onComplete: () => void,
  canOpen: () => boolean = () => true,
): Promise<void> {
  if (widgetOpen) return;
  widgetOpen = true;
  try {
    await loadCloudPaymentsScript();
    if (!canOpen()) return;
    const Constructor = window.cp?.CloudPayments;
    if (!Constructor) throw new Error("Не удалось инициализировать форму оплаты.");
    const widget = new Constructor();
    widget.oncomplete = onComplete;
    try {
      // The provider promise settles when the form closes. No callback grants access.
      const result = await widget.start(cloudPaymentsOptions(payload, method));
      if (typeof result === "object" && result !== null && "type" in result && result.type === "error") {
        throw new Error("Не удалось открыть форму оплаты. Попробуйте ещё раз.");
      }
    } finally {
      widget.oncomplete = undefined;
    }
  } finally {
    widgetOpen = false;
  }
}
