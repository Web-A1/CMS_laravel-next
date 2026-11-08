import { ButtonLink } from "@/components/button/Button";

export default function SkipLink() {
  return (
    <ButtonLink
      href="#main-content"
      className="skip-link" fullWidth={false} variant="secondary"
      data-ui="site.skipLink"
    >
      Перейти к содержимому
    </ButtonLink>
  );
}
