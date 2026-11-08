import type { Metadata, Viewport } from "next";
import { isSiteIndexingEnabled } from "@/lib/siteIndexingPolicy";
import type { CSSProperties } from "react";

import "../home.css";

import ContactModalProvider from "@/components/contact/ContactModalProvider";
import { SCHOOL_TELEGRAM_URL, SCHOOL_YOUTUBE_URL, SCHOOL_VK_URL } from "@/lib/contactLinks";
import ContactModalSlot from "@/components/contact/ContactModalSlot";
import SupportTicketModal from "@/components/support/SupportTicketModal";
import ContactChannelButton from "@/components/button/ContactChannelButton";
import { ButtonLink } from "@/components/button/Button";
import GeneratedImage, { type StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";
import SectionTitle from "@/components/content/SectionTitle";
import SectionIntro from "@/components/content/SectionIntro";
import { BrandSurface, SurfaceCard } from "@/components/content/SurfaceCard";
import TextLink from "@/components/content/TextLink";
import FaqDetailsList from "@/components/faq/FaqDetailsList";
import HomeHeroNonMobileScene from "@/components/home/HomeHeroNonMobileScene";
import HomeHeroEditor from "@/components/home/HomeHeroEditor";
import HomeHeroMotion from "@/components/home/HomeHeroMotion";
import HomeSmoothScroll from "@/components/home/HomeSmoothScroll";
import HomeHeroContactCta from "@/components/home/HomeHeroContactCta";
import HomeReviews from "@/components/home/HomeReviews";
import HomeSubjects from "@/components/home/HomeSubjects";
import HomeStats from "@/components/home/HomeStats";
import HomeTeamGallery from "@/components/home/HomeTeamGallery";
import HomeLicensePreview from "@/components/home/HomeLicensePreview";
import HomeLessonProgress from "@/components/home/HomeLessonProgress";
import HomeUnderstandingPuzzle from "@/components/home/HomeUnderstandingPuzzle";
import HomeFactsMotion from "@/components/home/HomeFactsMotion";
import HomeLearningComparison from "@/components/home/HomeLearningComparison";
import { academicComparison, schoolComparison } from "@/components/home/comparisonContent";
import comparisonMaterialMedia from "@/components/home/comparisonMaterialMedia.json";
import HomeEntranceMotion from "@/components/home/HomeEntranceMotion";
import { HOME_ENTRANCE_MOTION, HOME_HERO_MOTION } from "@/components/home/homeMotion";
import { listSubjects } from "@/lib/catalogApi";
import { listFaqs } from "@/lib/faqApi";
import { listReviews } from "@/lib/reviewsApi";
import { listHomeAuthors } from "@/lib/articlesApi";
import { articleSiteUrl, safeJsonLd } from "@/lib/articleSeo";
import { homeMetadata, homeStructuredData } from "@/lib/homeSeo";

export const viewport: Viewport = {
  themeColor: "#ffffff",
};

export function generateMetadata(): Metadata {
  return homeMetadata(articleSiteUrl(), isSiteIndexingEnabled(process.env.SITE_INDEXING_ENABLED, process.env.SITE_URL));
}

const deferredHomeBlocks = ["46:320", "46:306", "46:251"] as const;
const homeReviewsLimit = 20;

function staticHeroImage(name: string, width: number, height: number): StaticGeneratedImageAsset {
  return {
    avifSrc: `/images/v2/hero-cards/${name}.avif`,
    webpSrc: `/images/v2/hero-cards/${name}.webp`,
    width,
    height,
  };
}

// Keep the approved phone assets out of non-phone network requests as well as layout.
function MobileHeroImage({ image, fetchPriority }: { image: StaticGeneratedImageAsset; fetchPriority?: "high" }) {
  return <GeneratedImage image={image} media="(max-width: 480px)" alt="" loading="eager" fetchPriority={fetchPriority} />;
}

function staticTeacherCutoutImage(name: string): StaticGeneratedImageAsset {
  const variants = [384, 640, 896, 1254].map((size) => ({
    avifSrc: `/images/v2/home/team/${name}-cutout-20261019-${size}x${size}.avif`,
    webpSrc: `/images/v2/home/team/${name}-cutout-20261019-${size}x${size}.webp`,
    width: size,
    height: size,
  }));
  return {
    ...variants[variants.length - 1],
    variants,
  };
}

// Square portraits cover a 7:10 slot with 1rem of bottom bleed. Use that
// effective square size (slot height + bleed), rather than the narrower box.
const teacherImageSizes = [
  "(min-width: 64rem) min(calc(27.321429vw - 1.732143rem), 19.032143rem)",
  "(min-width: 60rem) calc(47.619048vw - 5.785714rem)",
  "(min-width: 40rem) calc(71.428571vw - 7.214286rem)",
  "(min-width: 481px) 27.25rem",
  "min(calc(117.142857vw - 4.785714rem), 27.25rem)",
].join(", ");

function staticHomeImage(name: string, width: number, height: number): StaticGeneratedImageAsset {
  return {
    avifSrc: `/images/v2/home/${name}.avif`,
    webpSrc: `/images/v2/home/${name}.webp`,
    width,
    height,
  };
}

const heroMainCardImage = staticHeroImage("icon-2", 432, 370);
const homeFreeLessonLaptopImage = staticHomeImage("free-lesson-laptop", 1098, 664);
const heroTopCardImages = {
  four: staticHeroImage("icon-4", 288, 286),
  five: staticHeroImage("icon-5", 288, 236),
} as const;

const heroCards = [
  {
    className: "home-hero__card home-hero__card--one",
    image: staticHeroImage("icon-1", 288, 275),
    fetchPriority: undefined,
  },
  {
    className: "home-hero__card home-hero__card--three",
    image: staticHeroImage("icon-3", 400, 296),
    fetchPriority: "high",
  },
] as const;

const studentLoveCards = [
  {
    title: "платформа",
    text: "Удобная платформа для обучения - занимайся в любом месте",
    illustration: "/images/home/benefits/platform-internet.svg",
    wide: true,
  },
  {
    title: "программа",
    text: "Курсы составлены согласно программе медицинских ВУЗов",
    illustration: "/images/home/benefits/program-diploma.svg",
    wide: true,
  },
  {
    title: "обратная связь",
    text: "Доступ к чату с преподавателем",
    illustration: "/images/home/benefits/feedback-comments.svg",
    wide: false,
  },
  {
    title: "оплата",
    text: "Доступна беспроцентная рассрочка",
    illustration: "/images/home/benefits/payment-installment.svg",
    wide: false,
  },
] as const;

const factCards = [
  {
    title: "Лицензия",
    text: <>№ Л035-01298-77/00659049 от 22.06.2023</>,
    tone: "license",
  },
  {
    title: <>Ты не учишь,<br />а{"\u00A0"}понимаешь</>,
    text: "Теория подкреплена практическими боксами, материал усваивается проще",
    tone: "feature",
  },
  {
    title: "Тема курса закрыта полностью",
    text: "Тебе не нужно искать дополнительный материал",
    tone: "lesson",
  },
] as const;

export default async function HomePage() {
  const [subjectsResult, reviewsResult, homeFaqsResult, authorsResult] = await Promise.allSettled([
    listSubjects(),
    listReviews({
      placement: "home",
      limit: homeReviewsLimit,
      fetchMode: "live",
    }),
    listFaqs({
      placement: "home.faq",
      fetchMode: "live",
    }),
    listHomeAuthors(),
  ]);

  if (subjectsResult.status === "rejected") {
    throw subjectsResult.reason;
  }

  const subjects = subjectsResult.value;
  const reviews = reviewsResult.status === "fulfilled" ? reviewsResult.value : [];
  const homeFaqs = homeFaqsResult.status === "fulfilled" ? homeFaqsResult.value : [];
  const teachers = authorsResult.status === "fulfilled" ? authorsResult.value.map((author) => ({
    id: author.id,
    name: author.name,
    slug: author.slug,
    text: author.homeRoleText,
    cutoutImage: staticTeacherCutoutImage(author.cutoutKey),
    wideCutout: author.cutoutKey === "demo-author-5",
  })) : [];
  const siteOrigin = articleSiteUrl();

  return (
    <ContactModalProvider>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(homeStructuredData(siteOrigin, reviews, SCHOOL_TELEGRAM_URL, homeFaqs, [SCHOOL_YOUTUBE_URL, SCHOOL_VK_URL])) }}
      />
      <HomeSmoothScroll />
      <HomeEntranceMotion />
      <main
        className="home-page bg-white ui-text-primary"
        style={{
          "--home-entrance-duration": `${HOME_ENTRANCE_MOTION.durationMs}ms`,
          "--home-entrance-stagger": `${HOME_ENTRANCE_MOTION.staggerMs}ms`,
          "--home-entrance-spring": HOME_ENTRANCE_MOTION.easing,
          "--home-promo-fade-duration": `${HOME_HERO_MOTION.promoFadeMs}ms`,
          "--home-promo-fade-easing": HOME_HERO_MOTION.easing,
          "--home-hero-copy-fade-duration": `${HOME_HERO_MOTION.copyFadeMs}ms`,
          "--home-hero-copy-fade-easing": HOME_HERO_MOTION.easing,
        } as CSSProperties}
        data-ui="home.page"
        data-deferred-figma-nodes={deferredHomeBlocks.join(",")}
      >
      <section className="home-hero page-gutter relative mx-auto flex w-full flex-col" data-ui="home.hero" data-hero-motion="pending">
        <noscript><style>{`.home-hero__non-mobile-card > picture, .home-hero__float-shadow { visibility: visible !important; }`}</style></noscript>
        <HomeHeroMotion />
        <HomeHeroNonMobileScene />
        {process.env.NODE_ENV === "development" ? <HomeHeroEditor /> : null}
        {/* Public poster preserves the composition without importing video. */}
        <div className="home-hero__media ui-video-frame" data-state="poster" aria-hidden="true" />
        <div className="home-hero__copy relative z-10 flex flex-col">
          <p className="home-hero__desktop-title" data-ui="home.hero.desktopTitle">
            <span data-hero-title-part>учись <span className="home-hero__title-accent">в меде</span></span>{" "}
            <span data-hero-title-part><span className="home-hero__title-accent">без страха</span> отчисления</span>{" "}
            <span data-hero-title-part>и долгов</span>
          </p>
          <div className="home-hero__title-wrap relative">
            <p className="home-hero__title" data-ui="home.hero.title">
              <span data-hero-title-part>учись <span className="home-hero__title-accent">в меде</span></span>{" "}
              <span data-hero-title-part><span className="home-hero__title-accent">без страха</span> отчисления</span>{" "}
              <span data-hero-title-part>и долгов</span>
            </p>
            <div className="home-hero__top-cards" aria-hidden="true">
              <div className="home-hero__card home-hero__card--four">
                <MobileHeroImage image={heroTopCardImages.four} />
              </div>
              <div className="home-hero__card home-hero__card--five">
                <MobileHeroImage image={heroTopCardImages.five} />
              </div>
            </div>
          </div>

          <div className="home-hero__subtitle-wrap relative">
            <h1 className="home-hero__subtitle home-hero__desktop-support" data-ui="home.hero.subtitle">
              курсы для студентов медиков с клиническими примерами — чтобы ты не зубрил, а понял!
            </h1>
            <div className="home-hero__small-cards" aria-hidden="true">
              {heroCards.map((card) => (
                <div key={card.image.avifSrc} className={card.className}>
                  <MobileHeroImage image={card.image} fetchPriority={card.fetchPriority} />
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="home-hero__main-card" aria-hidden="true">
          <div className="home-hero__main-card-media">
            <MobileHeroImage image={heroMainCardImage} />
          </div>
        </div>

        <HomeHeroContactCta />
      </section>

      <section
        className="home-section home-promo-video"
        aria-label="Промо-изображение школы"
        data-ui="home.promoVideo"
      >
        <div className="home-promo-video__media" data-state="preview" role="img" aria-label="Промо-изображение Christmedschool" />
      </section>

      <div className="home-sections" data-ui="home.sections">
        <HomeSubjects subjects={subjects} />

        <HomeStats />

        <section className="home-section home-love" data-ui="home.studentLove">
          <SectionIntro eyebrow="Преимущества CHRISTMEDSCHOOL" className="home-love__intro" dataUi="home.studentLove">
            почему студенты<br /> <span>так любят</span> наши курсы?
          </SectionIntro>
          <div className="home-card-grid" data-ui="home.studentLove.cards">
            {studentLoveCards.map((card) => (
              <SurfaceCard key={card.title} wide={card.wide} tone="subject" className="home-love__card" data-ui="home.studentLove.card">
                <div className="home-love__copy">
                  <h3 className="ui-card-title">{card.title}</h3>
                  <p className="ui-card-text ui-card-text--large">{card.text}</p>
                </div>
                <div className="home-love__illustration" aria-hidden="true">
                  <span
                    className="home-love__art"
                    style={{ maskImage: `url("${card.illustration}")` }}
                  />
                </div>
              </SurfaceCard>
            ))}
          </div>
        </section>

        {reviewsResult.status === "fulfilled" ? (
          <HomeReviews reviews={reviews} />
        ) : (
          <section className="home-section home-reviews" id="reviews" data-ui="home.reviews" data-state="unavailable">
            <SectionTitle data-ui="home.reviews.title">
              не только <span>любят,</span> но и <span>советуют</span> другим студентам
            </SectionTitle>
            <p className="home-reviews__empty ui-card-text" role="status" data-ui="home.reviews.unavailable">
              Отзывы временно недоступны.
            </p>
            <TextLink href="/reviews" data-ui="home.reviews.moreLink">
              Перейти к отзывам
            </TextLink>
          </section>
        )}

        <BrandSurface from="non-phone" className="home-section home-cta-card home-cta-card--lesson" data-ui="home.freeLesson">
          <div className="home-free-lesson__copy">
            <SectionTitle tone="on-dark" className="home-cta-card__title">
              смотри бесплатный урок и сам <span>убедись в качестве!</span>
            </SectionTitle>
            <p className="ui-card-text ui-card-text--large">
              Пиши нам в любом удобном мессенджере, и менеджер подберёт для тебя бесплатный урок.
            </p>
            <div className="home-free-lesson__channels" data-ui="home.freeLesson.channels">
              <ContactChannelButton surface="brand" channel="telegram" label="Telegram" href={SCHOOL_TELEGRAM_URL} aria-label="Написать в Telegram" />
              <ContactChannelButton surface="brand" channel="max" label="MAX" href="#" aria-label="Написать в MAX" />
            </div>
          </div>
          <div className="home-free-lesson__visual" data-ui="home.freeLesson.image">
            <GeneratedImage image={homeFreeLessonLaptopImage} alt="Бесплатный урок по анатомии на экране ноутбука" />
          </div>
        </BrandSurface>

        {teachers.length > 0 ? <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd({
            "@context": "https://schema.org",
            "@graph": teachers.map((teacher) => ({
              "@type": "Person",
              "@id": `${siteOrigin}/#teacher-${encodeURIComponent(teacher.slug)}`,
              name: teacher.name,
              description: teacher.text,
              image: `${siteOrigin}${teacher.cutoutImage.webpSrc}`,
              affiliation: { "@id": `${siteOrigin}/#organization` },
            })),
          }) }}
        /> : null}
        <section className="home-section home-team" id="teachers" data-ui="home.team">
          <div className="home-team__intro">
            <SectionTitle className="home-team__title" data-ui="home.team.title">
              наша команда — <br />
              это опытные <span>преподаватели и практики</span>
            </SectionTitle>
          </div>
          {teachers.length > 0 ? <HomeTeamGallery total={teachers.length}>
            {teachers.map((teacher) => (
              <article key={teacher.id} className="home-teacher-card">
                <div className={`home-teacher-card__image${teacher.wideCutout ? " home-teacher-card__image--wide-cutout" : ""}`}>
                  <GeneratedImage
                    image={teacher.cutoutImage}
                    sizes={teacherImageSizes}
                    alt=""
                  />
                </div>
                <div className="flex flex-col gap-1 lg:px-3">
                  <h3 className="ui-card-title">{teacher.name}</h3>
                  <p className="ui-card-text ui-card-text--compact">{teacher.text}</p>
                </div>
              </article>
            ))}
          </HomeTeamGallery> : <p className="ui-card-text" role="status">Команда временно недоступна.</p>}
        </section>

        <HomeFactsMotion>
          <SectionTitle id="home-facts-title" className="max-w-lg" data-ui="home.facts.title">
            мы — школа{" "}<br /><span>с образовательной лицензией</span>
          </SectionTitle>
          <div className="home-facts__grid">
            {factCards.map((card) => (
              <SurfaceCard key={card.tone} tone="subject" className={`home-fact-card home-fact-card--${card.tone}`}>
                <div className="home-fact-card__copy">
                  {card.tone === "license" ? (
                    <h3 className="ui-card-title">{card.title}</h3>
                  ) : (
                    <p className="ui-card-title">{card.title}</p>
                  )}
                  <p className="ui-card-text ui-card-text--large">{card.text}</p>
                </div>
                <span className="home-fact-card__decoration" aria-hidden="true">
                  {card.tone === "feature" ? <HomeUnderstandingPuzzle /> : null}
                  {card.tone === "lesson" ? <HomeLessonProgress /> : null}
                </span>
                {card.tone === "license" ? <HomeLicensePreview /> : null}
              </SurfaceCard>
            ))}
          </div>
        </HomeFactsMotion>

        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd({
            "@context": "https://schema.org",
            "@graph": [academicComparison, schoolComparison].map((material) => ({
              "@type": "ImageObject",
              "@id": `${siteOrigin}${material.src}#image`,
              contentUrl: `${siteOrigin}${material.src}`,
              name: material.alt,
              description: `${material.alt}. ${material.caption}`,
              caption: material.caption,
              citation: material.caption,
              isPartOf: { "@id": `${siteOrigin}/#webpage` },
            })),
          }) }}
        />
        <section className="home-section home-learning-comparison" id="learning-comparison" data-ui="home.learningComparisonSection">
          <SectionTitle className="home-learning-comparison__title max-w-xl">
            мы сделали <span>понятными</span>{" "}<br />
            академические пособия
          </SectionTitle>
          <HomeLearningComparison
            before={{ src: "/images/home/comparison/academic-desktop-safe-v3.png", panoramicImage: comparisonMaterialMedia.panoramic.academic, shortLandscapeImage: comparisonMaterialMedia.shortLandscape.academic, narrowLandscapeImage: comparisonMaterialMedia.narrowLandscape.academic, phonePortraitImage: comparisonMaterialMedia.phonePortrait.academic, tabletPortraitImage: comparisonMaterialMedia.tabletPortrait.academic, alt: "Фрагмент академического учебника о щитовидной железе" }}
            after={{ src: "/images/home/comparison/school-desktop-safe-v3.png", panoramicImage: comparisonMaterialMedia.panoramic.school, shortLandscapeImage: comparisonMaterialMedia.shortLandscape.school, narrowLandscapeImage: comparisonMaterialMedia.narrowLandscape.school, phonePortraitImage: comparisonMaterialMedia.phonePortrait.school, tabletPortraitImage: comparisonMaterialMedia.tabletPortrait.school, alt: "Конспект CHRISTMEDSCHOOL: строение и размеры щитовидной железы" }}
          />
        </section>

        <section className="home-section home-faq" id="faq" data-ui="home.faq">
          <div className="home-faq__intro">
            <div className="home-faq__copy">
              <SectionTitle data-ui="home.faq.title">часто задаваемые <span>вопросы</span></SectionTitle>
              <p className="ui-card-text ui-card-text--large ui-card-text--relaxed" data-ui="home.faq.description">
                Ответы на частые вопросы об онлайн-школе: от регистрации и оплаты курсов до обучения и решения технических проблем.
              </p>
            </div>
            <SurfaceCard className="home-faq__support" tone="subject" id="contacts" data-ui="home.faq.support">
              <div className="flex flex-col gap-2">
                <div className="flex items-center gap-3">
                  <span className="inline-flex shrink-0 [color:color-mix(in_srgb,var(--color-text-soft)_65%,var(--color-text-muted-ink))]" aria-hidden="true">
                    {/* Phosphor Icons headset-light; license: frontend/licenses/phosphor-icons.txt */}
                    <svg className="size-8" viewBox="0 0 256 256" fill="currentColor">
                      <path d="M200.47,56.07A101.37,101.37,0,0,0,128.77,26H128A102,102,0,0,0,26,128v56a22,22,0,0,0,22,22H64a22,22,0,0,0,22-22V144a22,22,0,0,0-22-22H38.2A90,90,0,0,1,128,38h.68a89.71,89.71,0,0,1,89.13,84H192a22,22,0,0,0-22,22v40a22,22,0,0,0,22,22h26v2a26,26,0,0,1-26,26H136a6,6,0,0,0,0,12h56a38,38,0,0,0,38-38V128A101.44,101.44,0,0,0,200.47,56.07ZM64,134a10,10,0,0,1,10,10v40a10,10,0,0,1-10,10H48a10,10,0,0,1-10-10V134Zm118,50V144a10,10,0,0,1,10-10h26v60H192A10,10,0,0,1,182,184Z" />
                    </svg>
                  </span>
                  <p className="ui-card-title ui-card-title--compact">Нужна помощь?</p>
                </div>
                <p className="ui-card-text ui-card-text--compact">Если не нашли ответ, напишите в техническую поддержку.</p>
              </div>
              <SupportTicketModal />
            </SurfaceCard>
          </div>
          <div className="home-faq__content">
            {homeFaqsResult.status === "fulfilled" ? (
              <FaqDetailsList
                faqs={homeFaqs}
                className="home-faq__list"
                itemClassName="home-faq__item"
                answerClassName="ui-card-text whitespace-pre-line"
                desktopVariant="lined"
                dataUi="home.faq.list"
              />
            ) : (
              <p className="ui-card-text" role="status" data-ui="home.faq.unavailable">
                Ответы на вопросы временно недоступны.
              </p>
            )}
            <div className="home-faq__more">
              <ButtonLink href="/faq" variant="ghost" fullWidth={false} data-ui="home.faq.moreLink">Смотреть все вопросы</ButtonLink>
            </div>
          </div>
        </section>

      </div>

      <ContactModalSlot dataUiPrefix="home.contactModal" />
      </main>
    </ContactModalProvider>
  );
}
