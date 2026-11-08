import type { ReactNode } from "react";

// Public links visible in the school footer. PDF files are not part of this copy.
const documents = {
  offer: "https://staging.christmedschool.com/docs/offer-agreement.pdf",
  privacy: "https://staging.christmedschool.com/docs/privacy-policy.pdf",
  license: "https://staging.christmedschool.com/documents/лицензия.pdf",
} as const;

type Props = {
  readonly documentId: keyof typeof documents;
  readonly children: ReactNode;
  readonly className?: string;
  readonly dataUi: string;
};

export default function LegalDocumentLink({ documentId, children, className, dataUi }: Props) {
  return (
    <a href={documents[documentId]} target="_blank" rel="noopener noreferrer" className={className} data-ui={dataUi}>
      {children}
    </a>
  );
}
