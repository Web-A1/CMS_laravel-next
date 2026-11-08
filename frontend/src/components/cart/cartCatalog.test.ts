import assert from "node:assert/strict";
import test from "node:test";
import type { CourseCatalogItem } from "../../lib/catalogApi";
const path = "./cartCatalog.ts";
const { resolveCartEntries }: typeof import("./cartCatalog") = await import(path);
const commercePath = "../../lib/commerceApi.ts";
const { adaptCourseCheckout }: typeof import("../../lib/commerceApi") = await import(commercePath);
const courseCartOffers = (courses: ReadonlyArray<CourseCatalogItem>) => courses.map(adaptCourseCheckout).filter((item) => item !== null);

const course: CourseCatalogItem = {
  id: 8, itemId: "course:anatomiya:osteologiya", title: "Остеология", shortTitle: null,
  courseSlug: "osteologiya", subjectSlug: "anatomiya", subjectTitle: "Анатомия", price: 1000,
  sellable: { sku: "course-8", kind: "course", currency: "RUB", price: 2000, priceVersion: 2 }, lessons: [],
};

test("legacy course remains buyable even when the separate SKU catalog is empty", () => {
  const offers = courseCartOffers([course]);
  assert.equal(offers[0]?.itemId, course.itemId);
  assert.equal(offers[0]?.price, 2000);
  assert.equal(resolveCartEntries([course.itemId], offers)[0]?.status, "available");
  assert.deepEqual(courseCartOffers([{ ...course, sellable: null }]), []);
});

test("saved unavailable and unknown positions stay visible alongside course IDs and new SKUs", () => {
  const legacy = courseCartOffers([course])[0];
  const sku = { ...legacy, itemId: "new-sku", sku: "new-sku" };
  const entries = resolveCartEntries([course.itemId, "removed-course", sku.itemId, "missing-sku"], [legacy, sku], [{ itemId: "removed-course", title: "Эмбриология" }]);
  assert.equal(entries.length, 4);
  assert.deepEqual(entries.map((item) => item.status), ["available", "unavailable", "available", "unavailable"]);
  assert.equal(entries[1].title, "Эмбриология");
  assert.equal(entries[3].title, "Недоступный товар");
  assert.equal(entries.reduce((sum, item) => sum + (item.offer?.price ?? 0), 0), 4000);
  assert.equal(resolveCartEntries(["removed-course"], [])[0]?.status, "unavailable");
});
