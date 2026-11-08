import { notFound } from "next/navigation";
import ButtonSystemPreview from "@/components/button/ButtonSystemPreview";

export default function ButtonSystemPage() {
  if (process.env.NODE_ENV !== "development") notFound();
  return <main className="site-shell px-6 py-10"><ButtonSystemPreview /></main>;
}
