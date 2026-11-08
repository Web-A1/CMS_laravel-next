import type { PublicReview } from "@/lib/reviewsApi";

function reviewSourceKind(sourceLink: PublicReview["sourceLinks"][number] | null): "vk" | "yandex" | null {
  if (!sourceLink) {
    return null;
  }

  if (sourceLink.type === "vk_webarchive") {
    return "vk";
  }

  if (sourceLink.type === "yandex_maps_webarchive") {
    return "yandex";
  }

  return null;
}

function SourceMark({ kind }: { readonly kind: "vk" | "yandex" }) {
  if (kind === "vk") {
    return (
      <svg className="review-modal__source-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <rect width="64" height="64" rx="16" fill="#2787F5" />
        <path
          fill="#fff"
          d="M34.8 43.6c-13.7 0-21.5-9.4-21.8-25.1h6.9c.2 11.5 5.3 16.4 9.3 17.4V18.5h6.5v9.9c3.9-.4 8-5 9.4-9.9h6.5c-1.1 6-5.7 10.6-9 12.5 3.3 1.5 8.6 5.5 10.6 12.6h-7.1c-1.6-4.7-5.4-8.3-10.4-8.8v8.8h-.9Z"
        />
      </svg>
    );
  }

  return (
    <svg className="review-modal__source-mark" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        fill="#FF4433"
        d="M8 1C4.6862 1 2 3.6862 2 7C2 8.6563 2.6711 10.156 3.7565 11.2417C4.8422 12.328 7.4 13.9 7.55 15.55C7.57249 15.7974 7.7516 16 8 16C8.2484 16 8.42751 15.7974 8.45 15.55C8.6 13.9 11.1578 12.328 12.2435 11.2417C13.3289 10.156 14 8.6563 14 7C14 3.6862 11.3138 1 8 1Z"
      />
      <path
        fill="#fff"
        d="M8.00002 9.10015C9.15982 9.10015 10.1 8.15994 10.1 7.00015C10.1 5.84035 9.15982 4.90015 8.00002 4.90015C6.84023 4.90015 5.90002 5.84035 5.90002 7.00015C5.90002 8.15994 6.84023 9.10015 8.00002 9.10015Z"
      />
    </svg>
  );
}

export default function ReviewModalSourcePanel({ review }: { readonly review: PublicReview }) {
  const sourceLink = review.sourceLinks[0];
  const sourceKind = reviewSourceKind(sourceLink ?? null);

  if (!sourceLink) {
    return null;
  }

  return (
    <div className="review-modal__source-panel">
      {sourceKind ? <SourceMark kind={sourceKind} /> : null}
      <a className="review-modal__source-link" href={sourceLink.url} target="_blank" rel="noreferrer">
        {sourceLink.label}
      </a>
    </div>
  );
}
