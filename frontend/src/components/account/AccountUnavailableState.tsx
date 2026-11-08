type AccountUnavailableStateProps = {
  readonly retryHref: string;
};

export default function AccountUnavailableState({ retryHref }: AccountUnavailableStateProps) {
  return (
    <main
      className="content-shell page-content page-gutter mx-auto w-full bg-white ui-text-primary"
      data-ui="account.unavailable"
      data-state="unavailable"
    >
      <section className="mx-auto grid w-full max-w-xl gap-4 py-8" aria-labelledby="account-unavailable-title">
        <h1 id="account-unavailable-title" className="text-2xl font-bold leading-tight sm:text-3xl">
          Личный кабинет временно недоступен
        </h1>
        <p className="text-sm leading-6 ui-text-secondary">
          Не удалось проверить текущую сессию. Данные аккаунта не изменены.
        </p>
        <ButtonLink variant="secondary" fullWidth={false} className="w-full sm:w-fit" href={retryHref}>
          Повторить попытку
        </ButtonLink>
      </section>
    </main>
  );
}
import { ButtonLink } from "@/components/button/Button";
