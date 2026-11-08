import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const homePageSource = readFileSync(new URL("./(site)/page.tsx", import.meta.url), "utf-8");
const homeTeamSource = readFileSync(
  new URL("../components/home/HomeTeamGallery.tsx", import.meta.url),
  "utf-8",
);
const homeReviewsSource = readFileSync(
  new URL("../components/home/HomeReviews.tsx", import.meta.url),
  "utf-8",
);

test("keeps horizontal static content reachable from the keyboard", () => {
  assert.match(homePageSource, /<HomeTeamGallery\s/);
  assert.match(
    homeTeamSource,
    /className="home-teacher-track"\s+role="region"\s+tabIndex=\{0\}\s+aria-label="Преподаватели"/,
  );
  assert.match(
    homeReviewsSource,
    /className="home-reviews-track home-reviews-static-preview"\s+role="region"\s+tabIndex=\{0\}\s+aria-label="Отзывы студентов"/,
  );
});
