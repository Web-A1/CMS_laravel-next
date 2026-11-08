import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./commerceApi.ts";
const api: typeof import("./commerceApi") = await import(modulePath);
const offer = { sku: "legacy-product-318", kind: "guide", entityType: "guide", entityId: 2, title: "Методичка", price: 9900, currency: "RUB", priceVersion: 1, publicPath: "/offers/legacy-product-318", accessPath: "/lk/guides/legacy-file-100", tariff: null, atoms: [{ kind: "guide", id: 2, title: "Методичка", accessPath: "/lk/guides/legacy-file-100" }] };
const tariff = { code: "ordinary", label: "Обычный", supportDescription: "Обычный объём личной работы преподавателя." };

test("AC-COMMERCE-IDENTITY-POSITIVE: exact safe offer and existing course adapter", () => {
  assert.equal(api.parseCheckoutCatalogItem(offer).itemId, offer.sku);
  const course = { id: 2, itemId: "course:anatomy:bones", subjectSlug: "anatomy", subjectTitle: "Анатомия", courseSlug: "bones", title: "Кости", shortTitle: null, price: 1000, sellable: { sku: "course-anatomy-bones", kind: "course", price: 1000, currency: "RUB", priceVersion: 1 }, lessons: [] };
  assert.equal(api.adaptCourseCheckout(course)?.itemId, course.itemId);
  assert.equal(api.adaptCourseCheckout({ ...course, sellable: null }), null);
});
test("AC-COMMERCE-IDENTITY-COLLISION-DENIED: numeric collision, wrong SKU, multiple positions", () => {
  const item = api.parseCheckoutCatalogItem(offer);
  const purchased = { sellable_sku: item.sku, sellable_kind: "guide", purchased_entity_type: "guide", purchased_entity_id: 2, tariff: null };
  assert.equal(api.matchedOrderTariff({ items: [purchased] }, item), null);
  for (const invalid of [{ ...purchased, sellable_kind: "course", purchased_entity_type: "course" }, { ...purchased, sellable_sku: "legacy-product-344" }, { ...purchased, purchased_entity_id: 3 }, { ...purchased, purchased_entity_type: "lesson" }]) assert.throws(() => api.matchedOrderTariff({ items: [invalid] }, item));
  assert.throws(() => api.matchedOrderTariff({ items: [purchased, purchased] }, item));
  assert.throws(() => api.matchedOrderTariff({ items: [] }, item));
});
test("AC-TARIFF-PARSER-NEGATIVE: nullable bounded exact safe tariff", () => {
  assert.deepEqual(api.parseSellableTariff(tariff), tariff);
  assert.equal(api.parseSellableTariff(null), null);
  for (const invalid of [undefined, { ...tariff, code: "full" }, { ...tariff, extra: true }, { ...tariff, label: " " }, { ...tariff, label: "a".repeat(81) }, { ...tariff, supportDescription: "a".repeat(501) }, { ...tariff, label: "<b>Обычный</b>" }, { ...tariff, supportDescription: "bad\ntext" }, { ...tariff, supportDescription: 42 }]) assert.throws(() => api.parseSellableTariff(invalid));
});
test("purchased tariff is frozen and never falls back to current offer terms", () => {
  const item = api.parseCheckoutCatalogItem({ ...offer, tariff: { ...tariff, label: "Новое название" } });
  const purchased = { sellable_sku: item.sku, sellable_kind: item.kind, purchased_entity_type: item.entityType, purchased_entity_id: item.entityId, tariff };
  assert.equal(api.matchedOrderTariff({ items: [purchased] }, item)?.label, "Обычный");
  assert.equal(api.matchedOrderTariff({ items: [{ ...purchased, tariff: null }] }, item), null);
});
test("rejects shape leaks, identity mismatch, unsafe paths and duplicate atoms", () => {
  for (const invalid of [{ ...offer, sourceUrl: "/secret.pdf" }, { ...offer, entityType: "course" }, { ...offer, entityId: null }, { ...offer, price: "9900" }, { ...offer, price: -1 }, { ...offer, accessPath: "//evil.test/x" }, { ...offer, accessPath: "/lk/../secret" }, { ...offer, publicPath: "/x\\evil" }, { ...offer, atoms: [...offer.atoms, ...offer.atoms] }, { ...offer, atoms: [{ ...offer.atoms[0], sourceHash: "secret" }] }]) assert.throws(() => api.parseCheckoutCatalogItem(invalid));
});
