import assert from "node:assert/strict";
import test from "node:test";
import type { PublicReview } from "./reviewsApi";
import type { PublicFaqItem } from "./faqApi";

const modulePath = "./homeSeo.ts";
const { homeMetadata, homeStructuredData, homeReviewStructuredData }: typeof import("./homeSeo") = await import(modulePath);
const articleModulePath = "./articleSeo.ts";
const { safeJsonLd } = await import(articleModulePath);

test("FAQ markup follows the current selection and preserves question-answer pairs safely", () => {
  const first: PublicFaqItem = { id: 1, slug: "payment", question: "Как оплатить?", answer: "Ответ\nвторой абзац", category: null, subject: null, sortOrder: 0, publishedAt: null };
  const second = { ...first, id: 2, slug: "access", question: "Где урок?", answer: '</script><script>alert("x")</script>' };
  const graph = (faqs: ReadonlyArray<PublicFaqItem>) => homeStructuredData("https://school.test", [], undefined, faqs)["@graph"];
  const page = graph([first, second])[2];
  assert.ok("mainEntity" in page);
  assert.deepEqual(page["@type"], ["WebPage", "FAQPage"]);
  assert.deepEqual(page.mainEntity?.map((q) => [q.name, q.acceptedAnswer.text]), [[first.question, first.answer], [second.question, second.answer]]);
  const changed = graph([{ ...second, answer: "Обновлённый ответ" }])[2];
  assert.ok("mainEntity" in changed);
  assert.equal(changed.mainEntity?.length, 1);
  assert.equal(changed.mainEntity?.[0].acceptedAnswer.text, "Обновлённый ответ");
  assert.doesNotMatch(JSON.stringify(changed), /Как оплатить/);
  for (const faqs of [[], [{ ...first, answer: " " }], [{ ...first, question: " " }]]) {
    const empty = graph(faqs)[2];
    assert.equal(empty["@type"], "WebPage");
    assert.ok(!("mainEntity" in empty));
  }
  const json = safeJsonLd(homeStructuredData("https://school.test", [], undefined, [second]));
  assert.doesNotMatch(json, /<\/script>/);
  assert.equal(JSON.parse(json)["@graph"][2].mainEntity[0].acceptedAnswer.text, second.answer);
});

test("home metadata follows the configured origin and keeps closed environments noindex", () => {
  for (const origin of ["http://localhost:3000", "https://staging.christmedschool.com", "https://christmedschool.com"]) {
    for (const enabled of [false, true]) {
      const metadata = homeMetadata(origin, enabled);
      assert.equal(metadata.title, "Онлайн-курсы для студентов медиков");
      assert.equal(metadata.description, "Курсы по анатомии, гистологии, биохимии и другим медицинским дисциплинам. Видеоуроки и конспекты для подготовки к занятиям и экзаменам. Бесплатный урок");
      assert.deepEqual(metadata.authors, [{ name: "LMS developer", url: "https://example.invalid/demo" }]);
      assert.equal(metadata.alternates?.canonical, `${origin}/`);
      assert.deepEqual(metadata.robots, { index: enabled, follow: enabled });
      assert.equal(metadata.openGraph?.url, `${origin}/`);
      assert.equal(metadata.openGraph?.description, metadata.description);
      assert.equal(metadata.twitter?.description, metadata.description);
      assert.equal(metadata.openGraph?.title, `${metadata.title} | Christmedschool`);
    }
  }
});

test("school profiles reference the confirmed channels while support stays a ContactPoint", () => {
  const profiles = ["https://example.invalid/demo", "https://example.invalid/demo"];
  const [organization] = homeStructuredData("https://school.test", [], "https://example.invalid/demo", [], profiles)["@graph"];
  assert.ok("sameAs" in organization);
  assert.deepEqual(organization.sameAs, profiles);
  assert.ok("contactPoint" in organization);
  assert.equal(organization.contactPoint?.url, "https://example.invalid/demo");
  assert.ok(!("sameAs" in homeStructuredData("https://school.test")["@graph"][0]));
});

test("home graph connects the real school, site and page without unsupported reviews or offers", () => {
  const origin = "https://school.test";
  const data = homeStructuredData(origin);
  const [organization, website, page] = data["@graph"];
  assert.equal(organization["@type"], "EducationalOrganization");
  assert.ok("publisher" in website);
  assert.ok("about" in page);
  assert.equal(website.publisher?.["@id"], organization["@id"]);
  assert.equal(page.isPartOf?.["@id"], website["@id"]);
  assert.equal(page.about?.["@id"], organization["@id"]);
  assert.equal(page.url, homeMetadata(origin, true).alternates?.canonical);
  assert.ok(data["@graph"].every((entry) => entry.url === `${origin}/`));
  assert.doesNotMatch(JSON.stringify(data), /aggregateRating|reviewCount|Offer|Course|FAQPage|SearchAction|christmedschool\.com/);
});

const review: PublicReview = {
  id: 42, displayName: "Анна", text: "Полный отзыв\n\n" + "Длинный текст. ".repeat(100),
  reviewWrittenAt: "2026-10-01", publishedAt: "2026-10-02", authorPhotoUrl: null,
  source: "manual", importOrigin: "manual", subject: null, course: null, sourceLinks: [], media: [],
};

test("home reviews preserve the full text, real author and visible date without inventing ratings", () => {
  const [node] = homeReviewStructuredData("https://school.test", [review]);
  assert.equal(node.reviewBody, review.text);
  assert.deepEqual(node.author, { "@type": "Person", name: "Анна" });
  assert.equal(node.datePublished, review.reviewWrittenAt);
  assert.deepEqual(node.itemReviewed, { "@id": "https://school.test/#organization" });
  assert.equal(node.url, "https://school.test/reviews?review=42");
  assert.doesNotMatch(JSON.stringify(node), /reviewRating|aggregateRating/);
  assert.equal(homeStructuredData("https://school.test", [review])["@graph"].length, 4);
});

test("only real nonempty reviews are marked up and invalid or absent dates stay absent", () => {
  assert.deepEqual(homeReviewStructuredData("https://school.test", []), []);
  assert.deepEqual(homeReviewStructuredData("https://school.test", [{ ...review, text: "  " }, { ...review, displayName: " " }]), []);
  for (const reviewWrittenAt of [null, "invalid"]) {
    const [node] = homeReviewStructuredData("https://school.test", [{ ...review, reviewWrittenAt }]);
    assert.equal(node.datePublished, undefined);
  }
});

test("course reviews identify the actual course and omit unknown course URLs", () => {
  const course: NonNullable<PublicReview["course"]> = {
    id: 8, itemId: "course-8", slug: "osteologiya", title: "Остеология", shortTitle: null,
    price: 0, subjectSlug: "anatomiya", subjectTitle: "Анатомия",
  };
  const [node] = homeReviewStructuredData("https://school.test", [{ ...review, course }]);
  assert.ok("url" in node.itemReviewed);
  assert.equal(node.itemReviewed.url, "https://school.test/subjects/anatomiya/osteologiya");
  const [withoutUrl] = homeReviewStructuredData("https://school.test", [{ ...review, course: { ...course, subjectSlug: null } }]);
  assert.ok(!("url" in withoutUrl.itemReviewed));
});

test("review text cannot break out of the JSON-LD script", () => {
  const text = '</script><script>alert("x")</script>';
  const json = safeJsonLd(homeStructuredData("https://school.test", [{ ...review, text }]));
  assert.doesNotMatch(json, /<\/script>/);
  assert.equal(JSON.parse(json)["@graph"][3].reviewBody, text);
});
