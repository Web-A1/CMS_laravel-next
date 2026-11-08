import type { Metadata } from "next";
import { redirect } from "next/navigation";

import Button, { ButtonRouteLink } from "@/components/button/Button";
import AccountUnavailableState from "@/components/account/AccountUnavailableState";
import TrustedBrowserPanel from "@/components/account/TrustedBrowserPanel";
import PaymentHistory from "@/components/account/PaymentHistory";
import AccountWorkspace from "@/components/account/AccountWorkspace";
import AccountNameForm from "@/components/account/AccountNameForm";
import { LearningRouteAnnouncer } from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl, loadAccountProfile } from "@/lib/accountAuth";

const lkSectionClass =
  "min-w-0";
const lkDescriptionListClass = "mt-3 max-w-xl divide-y divide-[var(--color-border-subtle)] border-y border-[var(--color-border-subtle)]";
const lkDescriptionRowClass = "grid min-w-0 gap-1 py-2 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-4";

export const metadata: Metadata = {
  title: "Личный кабинет",
  description: "Личный кабинет Christmedschool: профиль, обучение, корзина и статусы доступа.",
  robots: {
    index: false,
    follow: false,
  },
};

export default async function LkPage() {
  const apiBaseUrl = accountApiBaseUrl();
  const account = await loadAccountProfile(apiBaseUrl, "/lk");

  if (account.status === "unauthenticated") {
    redirect("/auth?mode=login");
  }

  if (account.status === "unavailable") {
    return <AccountUnavailableState retryHref="/lk" />;
  }

  const profile = account.profile;
  const isImpersonating = profile.impersonation?.status === "active";

  if (!isImpersonating && (profile.must_verify_phone ?? profile.phone_verified === false)) {
    redirect("/auth?mode=verify-phone");
  }

  return (
    <main className="content-shell page-content page-gutter mx-auto min-h-dvh w-full bg-white ui-text-primary" data-ui="lk.dashboard">
      <div className="mx-auto grid w-full gap-6">
        {isImpersonating ? (
          <div className="rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-4 text-sm leading-6 ui-text-tertiary" role="status" data-ui="lk.impersonationNotice">
            Режим просмотра пользователя: изменения профиля, заказов, обучения и защищённых материалов отключены.
          </div>
        ) : null}
        <LearningRouteAnnouncer headingId="lk-dashboard-title" title="Личный кабинет" />

        <AccountWorkspace content={{
          benefits: (
            <section className={lkSectionClass} aria-labelledby="lk-section-title" data-ui="lk.benefitsSummary" data-state="empty">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <p className="text-sm leading-5 ui-text-secondary">
                    Баланс, активные промокоды и ближайшее сгорание появятся после синхронизации бонусов.
                  </p>
                </div>
              </div>

              <dl className={lkDescriptionListClass}>
                <div className={lkDescriptionRowClass} data-ui="lk.benefitsSummary.coinsBalance">
                  <dt className="text-sm ui-text-muted-ink">Доступно монеток</dt>
                  <dd className="min-w-0 text-sm ui-text-primary">Нет данных</dd>
                </div>
                <div className={lkDescriptionRowClass} data-ui="lk.benefitsSummary.expiringCoins">
                  <dt className="text-sm ui-text-muted-ink">Ближайшее сгорание</dt>
                  <dd className="min-w-0 text-sm ui-text-primary">Нет данных</dd>
                </div>
                <div className={lkDescriptionRowClass} data-ui="lk.benefitsSummary.bestPromo">
                  <dt className="text-sm ui-text-muted-ink">Активный промокод</dt>
                  <dd className="min-w-0 text-sm ui-text-primary">Нет данных</dd>
                </div>
              </dl>

              <p className="mt-4 text-sm leading-5 ui-text-secondary" data-ui="lk.benefitsSummary.earnHint">
                Монетки начисляются за покупки, заполнение профиля, опросы, отзывы и реферальную программу.
              </p>
              {isImpersonating ? (
                <p className="mt-5 text-sm leading-5 ui-text-secondary">Корзина недоступна в режиме просмотра.</p>
              ) : (
                <div className="mt-5">
                  <ButtonRouteLink href="/lk/cart" className="w-full sm:w-fit" fullWidth={false} variant="secondary" data-ui="lk.benefitsSummary.cartLink">
                    Использовать в корзине
                  </ButtonRouteLink>
                </div>
              )}
            </section>
          ),
          profile: (
            <section className={lkSectionClass} aria-labelledby="lk-section-title" data-ui="lk.profileSummary">
              <AccountNameForm initialName={profile.name ?? ""} canUpdate={!isImpersonating} />
              <dl className="mt-9 max-w-xl divide-y divide-[var(--color-border-subtle)] border-y border-[var(--color-border-subtle)]">
                <div className="grid min-w-0 gap-1 py-4 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4" data-ui="lk.profileSummary.email">
                  <dt className="text-sm font-medium ui-text-tertiary">Email</dt>
                  <dd className="min-w-0 break-words text-sm ui-text-emphasis">{profile.email || "Не указан"}</dd>
                </div>
                <div className="grid min-w-0 gap-1 py-4 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-4" data-ui="lk.profileSummary.phone">
                  <dt className="text-sm font-medium ui-text-tertiary">Телефон</dt>
                  <dd className="min-w-0 break-words text-sm ui-text-emphasis">{profile.phone || "Не указан"}</dd>
                </div>
              </dl>
            </section>
          ),
          security: (
            <div className="grid gap-6">
              <section className={lkSectionClass} aria-labelledby="lk-section-title" data-ui="lk.security">
                <dl className="max-w-xl divide-y divide-[var(--color-border-subtle)] border-y border-[var(--color-border-subtle)]">
                  <div className={lkDescriptionRowClass} data-ui="lk.security.phoneStatus">
                    <dt className="text-sm ui-text-muted-ink">Телефон</dt>
                    <dd className="min-w-0 text-sm ui-text-primary">
                      {profile.phone_verified ?? !profile.must_verify_phone ? "Подтвержден" : "Нужно подтвердить"}
                    </dd>
                  </div>
                  <div className={lkDescriptionRowClass} data-ui="lk.security.emailStatus">
                    <dt className="text-sm ui-text-muted-ink">Email</dt>
                    <dd className="min-w-0 text-sm ui-text-primary">
                      {profile.email_verified ?? !profile.must_verify_email ? "Подтвержден" : "Нужно подтвердить"}
                    </dd>
                  </div>
                </dl>
                <div className="mt-5" data-ui="lk.security.passwordForm" data-state="endpoint-pending">
                  <h2 className="text-sm font-medium ui-text-primary">Смена пароля</h2>
                  <p className="mt-2 text-sm leading-5 ui-text-secondary">
                    Смена пароля в ЛК будет проходить с проверкой текущего пароля.
                  </p>
                  <Button type="button" className="mt-3" variant="secondary" disabled data-ui="lk.security.passwordChangeButton">
                    Смена пока недоступна
                  </Button>
                </div>
              </section>

              {isImpersonating ? null : <TrustedBrowserPanel apiBaseUrl={apiBaseUrl} />}
            </div>
          ),
          orders: (
            <section className={lkSectionClass} aria-labelledby="lk-section-title" data-ui="lk.ordersSummary">
              {isImpersonating ? null : <PaymentHistory apiBaseUrl={apiBaseUrl} />}
              {isImpersonating ? (
                <p className="mt-5 text-sm leading-5 ui-text-secondary">Оформление заказов недоступно в режиме просмотра.</p>
              ) : (
                <div className="mt-5">
                  <ButtonRouteLink href="/lk/cart" className="w-full sm:w-fit" fullWidth={false} variant="secondary" data-ui="lk.ordersSummary.cartLink">
                    Открыть корзину
                  </ButtonRouteLink>
                </div>
              )}
            </section>
          ),
          support: (
            <section className={lkSectionClass} aria-labelledby="lk-section-title" data-ui="lk.supportSummary">
              <p className="text-sm leading-5 ui-text-secondary">
                Если с оплатой или доступом возникнет проблема, обращение должно сохранять контекст пользователя и заказа.
              </p>
            </section>
          ),
        }} />
      </div>
    </main>
  );
}
