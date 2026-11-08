"use client";

import { useMediaQueryState } from "@/components/home/useMediaQueryState";
import type { PublicFaqItem } from "@/lib/faqApi";
import "./faq-details.css";

type FaqDetailsListProps = {
  readonly faqs: ReadonlyArray<PublicFaqItem>;
  readonly className?: string;
  readonly itemClassName?: string;
  readonly summaryClassName?: string;
  readonly answerClassName?: string;
  readonly openFirst?: boolean;
  readonly desktopVariant?: "default" | "lined";
  readonly dataUi?: string;
};

export default function FaqDetailsList({
  faqs,
  className,
  itemClassName,
  summaryClassName,
  answerClassName,
  openFirst = false,
  desktopVariant = "default",
  dataUi = "faq.list",
}: FaqDetailsListProps) {
  const isPhone = useMediaQueryState("(max-width: 480px)") === "matches";

  if (faqs.length === 0) {
    return null;
  }

  return (
    <div
      className={[className, desktopVariant === "lined" ? "faq-details--desktop-lined" : ""].filter(Boolean).join(" ")}
      data-ui={dataUi}
    >
      {faqs.map((item, index) => (
        <details
          key={item.slug}
          className={itemClassName}
          name={desktopVariant === "lined" && !isPhone ? dataUi : undefined}
          open={openFirst && index === 0}
          data-ui={`${dataUi}.item`}
        >
          <summary className={summaryClassName}>
            {desktopVariant === "lined" ? (
              <span className="faq-details__question">
                <span>{item.question}</span>
                <span className="faq-details__question-size" aria-hidden="true">{item.question}</span>
              </span>
            ) : <span>{item.question}</span>}
            {desktopVariant === "lined" ? <span className="faq-details__toggle" aria-hidden="true" /> : null}
          </summary>
          <p className={answerClassName}>{item.answer}</p>
        </details>
      ))}
    </div>
  );
}
