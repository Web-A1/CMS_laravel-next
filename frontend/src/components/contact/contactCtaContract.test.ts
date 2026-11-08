import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const contactModalSource = await readFile(new URL("./ContactModal.tsx", import.meta.url), "utf8");
const contactModalProviderSource = await readFile(new URL("./ContactModalProvider.tsx", import.meta.url), "utf8");
const contactCssSource = await readFile(new URL("./contact.css", import.meta.url), "utf8");
const homePageSource = await readFile(new URL("../../app/(site)/page.tsx", import.meta.url), "utf8");
const homeHeroContactCtaSource = await readFile(new URL("../home/HomeHeroContactCta.tsx", import.meta.url), "utf8");
const contactLinksSource = await readFile(new URL("../../lib/contactLinks.ts", import.meta.url), "utf8");
const footerSource = await readFile(new URL("../footer/SiteFooter.tsx", import.meta.url), "utf8");

test("showcase contacts preserve the public school destination while MAX remains a placeholder", () => {
  assert.match(contactLinksSource, /SCHOOL_TELEGRAM_URL = "https:\/\/t\.me\/christmedschool_support"/);
  assert.equal(contactModalSource.match(/href:\s*["']#["']/g)?.length, 1);
  assert.match(contactModalSource, /href: SCHOOL_TELEGRAM_URL, label: "Telegram"/);
  assert.match(footerSource, /label: "Telegram", href: SCHOOL_TELEGRAM_URL/);
  assert.match(contactModalSource, /label:\s*["']Telegram["']/);
  assert.match(contactModalSource, /label:\s*["']MAX["']/);
  assert.doesNotMatch(contactModalSource, /target="_blank"/);
  assert.doesNotMatch(contactModalSource, /rel="noreferrer"/);
});

test("home contact actions keep their approved split behavior", () => {
  assert.doesNotMatch(homePageSource, /<ContactModalTrigger/);
  assert.match(homePageSource, /channel="telegram" label="Telegram" href=\{SCHOOL_TELEGRAM_URL\}/);
  assert.match(homePageSource, /channel="max" label="MAX" href="#"/);
  assert.match(homeHeroContactCtaSource, /<ContactModalTrigger/);
  assert.doesNotMatch(homePageSource, /home\.startCta|home-cta-card--start/);
  assert.match(homePageSource, /<SupportTicketModal/);
  assert.doesNotMatch(homePageSource, /variant="homeInverse"/);
  assert.doesNotMatch(homeHeroContactCtaSource, /href=/);
});

test("the material comparison uses its matching section anchor", () => {
  assert.match(homePageSource, /id="learning-comparison"/);
  assert.match(homePageSource, /<HomeLearningComparison/);
  assert.doesNotMatch(homePageSource, /home-video-card/);
  assert.doesNotMatch(homePageSource, /school-promo-video/);
});

test("focus restoration is owned by the mounted modal provider", () => {
  assert.match(contactModalProviderSource, /triggerButtonRef\.current\?\.focus\(\)/);
  assert.match(contactModalProviderSource, /useEffect\(\(\) => \{/);
  assert.doesNotMatch(contactModalSource, /triggerButtonRef/);
  assert.doesNotMatch(contactModalSource, /wasOpenRef/);
});

test("contact modal keeps channel buttons compact above mobile", () => {
  assert.match(contactCssSource, /\.contact-modal__channel \{[\s\S]*?flex: 1 1 0/);
  assert.match(contactCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.contact-modal__channel \{[\s\S]*?flex: 0 0 auto/);
});
