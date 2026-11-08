import Image from "next/image";
import { SurfaceCard } from "@/components/content/SurfaceCard";
import HomeStatsCountUp from "./HomeStatsCountUp";

const yandexReviewsUrl = "https://yandex.ru/maps/org/kursy_dlya_studentov_medikov/8220179633/reviews/";

function StatValue({ value, decimals = 0 }: { value: number; decimals?: number }) {
  const text = new Intl.NumberFormat("ru-RU", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value);

  return (
    <span className="inline-grid min-w-0 grid-cols-1 tabular-nums">
      <span className="sr-only">{text}</span>
      <span className="invisible col-start-1 row-start-1" aria-hidden="true">{text}</span>
      <span className="col-start-1 row-start-1" aria-hidden="true" data-count-up={value} data-count-decimals={decimals}>
        {text}
      </span>
    </span>
  );
}

export default function HomeStats() {
  return (
    <HomeStatsCountUp>
      <SurfaceCard tone="brand" variant="transparent" className="home-stats__card home-stats__card--years wrap-anywhere" data-ui="home.stats.years">
        <p className="flex max-w-full flex-wrap items-baseline gap-2 text-5xl leading-none font-bold tracking-tight ui-card-title--accent lg:flex-row lg:text-6xl">
          <StatValue value={7} /> <span className="text-base leading-5 font-normal tracking-normal">лет</span>
        </p>
        <p className="ui-card-text ui-card-text--large text-pretty sm:text-balance lg:text-wrap">
          Помогаем студентам перестать зубрить и начать понимать медицину
        </p>
      </SurfaceCard>

      <SurfaceCard tone="brand" variant="transparent" className="home-stats__card home-stats__card--rating wrap-anywhere sm:w-max sm:justify-self-start" data-ui="home.stats.rating">
        <p data-ui="home.stats.rating.value" className="flex w-full items-start justify-between gap-3 lg:justify-start">
          <span className="sr-only">
            5,0 <span className="text-lg font-normal tracking-normal">из 5</span>
          </span>
          <span className="home-stats__rating-mark inline-flex w-full flex-wrap items-start justify-between gap-3" aria-hidden="true">
            <span className="text-5xl leading-none font-bold tracking-tight lg:text-6xl"><StatValue value={5} decimals={1} /></span>
            <span className="flex h-10 flex-col items-end justify-between gap-0 mt-1.5 sm:h-9 lg:mt-2 lg:h-11">
              <svg className="h-3 w-15 sm:h-auto sm:w-14 lg:h-3 lg:w-15" width="60" height="12" viewBox="0 0 60 12" fill="currentColor" focusable="false">
                {[0, 12, 24, 36, 48].map((x) => (
                  <path key={x} transform={`translate(${x} 0)`} d="m6 0 1.8 3.8 4.2.6-3 3 .7 4.2L6 9.6l-3.7 2 .7-4.2-3-3 4.2-.6Z" />
                ))}
              </svg>
              <Image
                className="block h-auto w-15 sm:w-14 lg:w-16"
                src="/images/brands/yandex-classic-red-white.svg"
                width={350}
                height={135}
                alt=""
              />
            </span>
          </span>
        </p>
        <p className="ui-card-text ui-card-text--large text-pretty sm:w-max sm:whitespace-nowrap lg:whitespace-normal lg:text-wrap">
          <span className="sm:block">Реальный рейтинг школы</span>{" "}
          <span className="lg:block"><a className="underline underline-offset-2" href={yandexReviewsUrl}>в Яндексе</a> и отзывы<br className="hidden sm:block lg:hidden" />{" "}студентов,</span>{" "}
          <span className="lg:block">которые<br className="hidden sm:block lg:hidden" />{" "}успешно закрыли сессию</span>
        </p>
      </SurfaceCard>

      <SurfaceCard tone="brand" variant="transparent" className="home-stats__card home-stats__card--students wrap-anywhere" data-ui="home.stats.students">
        <p className="flex flex-col items-start gap-0 pt-0.5 text-4xl leading-none font-bold tracking-tight ui-card-title--accent sm:flex-row sm:items-baseline sm:gap-2 sm:pt-0 sm:whitespace-nowrap lg:flex-col lg:gap-0 lg:pt-1">
          <span className="inline-flex min-w-0 max-w-full items-center gap-1"><StatValue value={28000} /><span className="text-2xl font-medium tracking-normal">+</span></span>{" "}
          <span className="-mt-1 text-base leading-5 font-normal tracking-normal sm:mt-0 lg:-mt-1">студентов</span>
        </p>
        <p className="ui-card-text ui-card-text--large text-pretty sm:text-balance lg:text-wrap">
          Прошли обучение и подготовились к экзаменам вместе с нами
        </p>
      </SurfaceCard>
    </HomeStatsCountUp>
  );
}
