import type { Metadata } from "next";
import type { PublicReview } from "./reviewsApi";
import type { PublicFaqItem } from "./faqApi";

const homeTitle = "Онлайн-курсы для студентов медиков";
const homeDescription =
  "Курсы по анатомии, гистологии, биохимии и другим медицинским дисциплинам. Видеоуроки и конспекты для подготовки к занятиям и экзаменам. Бесплатный урок";

export function homeMetadata(origin: string, indexingEnabled: boolean): Metadata {
  const url = `${origin}/`;
  const title = `${homeTitle} | Christmedschool`;
  const image = {
    url: `${origin}/images/v2/home/free-lesson-laptop.webp`,
    width: 1098,
    height: 664,
    alt: "Бесплатный урок анатомии на платформе Christmedschool",
  };

  return {
    title: homeTitle,
    description: homeDescription,
    authors: [{ name: "LMS developer", url: "https://example.invalid/demo" }],
    alternates: { canonical: url },
    robots: { index: indexingEnabled, follow: indexingEnabled },
    openGraph: {
      type: "website",
      locale: "ru_RU",
      siteName: "Christmedschool",
      url,
      title,
      description: homeDescription,
      images: [image],
    },
    twitter: {
      card: "summary_large_image",
      title,
      description: homeDescription,
      images: [image],
    },
  };
}

export function homeReviewStructuredData(origin: string, reviews: ReadonlyArray<PublicReview>) {
  return reviews.filter((review) => review.text.trim() && review.displayName.trim()).map((review) => {
    const url = `${origin}/reviews?review=${review.id}`;
    const date = review.reviewWrittenAt;
    const course = review.course;

    return {
      "@type": "Review",
      "@id": `${url}#review`,
      url,
      inLanguage: "ru",
      reviewBody: review.text,
      author: { "@type": "Person", name: review.displayName },
      ...(date && Number.isFinite(Date.parse(date)) ? { datePublished: date } : {}),
      itemReviewed: course ? {
        "@type": "Course",
        name: course.title,
        provider: { "@id": `${origin}/#organization` },
        ...(course.subjectSlug && course.slug ? {
          url: `${origin}/subjects/${encodeURIComponent(course.subjectSlug)}/${encodeURIComponent(course.slug)}`,
        } : {}),
      } : { "@id": `${origin}/#organization` },
      isPartOf: { "@id": `${origin}/#webpage` },
    };
  });
}

export function homeStructuredData(origin: string, reviews: ReadonlyArray<PublicReview> = [], telegramUrl?: string, faqs: ReadonlyArray<PublicFaqItem> = [], socialProfiles: ReadonlyArray<string> = []) {
  const url = `${origin}/`;
  const organizationId = `${url}#organization`;
  const websiteId = `${url}#website`;
  const questions = faqs.filter((item) => item.question.trim() && item.answer.trim()).map((item) => ({
    "@type": "Question",
    "@id": `${url}#faq-question-${item.id}`,
    name: item.question,
    acceptedAnswer: { "@type": "Answer", text: item.answer },
  }));

  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "EducationalOrganization",
        "@id": organizationId,
        name: "Christmedschool",
        url,
        description: homeDescription,
        logo: `${origin}/images/brand/christmedschool-graduation-cap.svg`,
        ...(socialProfiles.length ? { sameAs: socialProfiles } : {}),
        ...(telegramUrl ? {
          contactPoint: {
            "@type": "ContactPoint",
            name: "Telegram",
            url: telegramUrl,
            contactType: "Поддержка и подбор курса",
            availableLanguage: "ru",
          },
        } : {}),
      },
      {
        "@type": "WebSite",
        "@id": websiteId,
        name: "Christmedschool",
        url,
        inLanguage: "ru",
        publisher: { "@id": organizationId },
      },
      {
        "@type": questions.length ? ["WebPage", "FAQPage"] : "WebPage",
        "@id": `${url}#webpage`,
        url,
        name: homeTitle,
        description: homeDescription,
        inLanguage: "ru",
        isPartOf: { "@id": websiteId },
        about: { "@id": organizationId },
        ...(questions.length ? { mainEntity: questions } : {}),
      },
      ...homeReviewStructuredData(origin, reviews),
    ],
  };
}
