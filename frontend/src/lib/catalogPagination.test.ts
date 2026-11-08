import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./catalogPagination.ts";
const { catalogPageSize, ARTICLE_PAGE_SIZES, REVIEW_PAGE_SIZES }: typeof import("./catalogPagination") = await import(modulePath);

test("catalog page sizes accept only exact scalar choices and preserve their defaults", () => {
  for (const [choices, defaultSize] of [[ARTICLE_PAGE_SIZES, 25], [REVIEW_PAGE_SIZES, 25]] as const) {
    assert.deepEqual(choices, [10, 25, 50]);
    assert.equal(catalogPageSize(undefined, choices, defaultSize), defaultSize);
    for (const size of choices) assert.equal(catalogPageSize(String(size), choices, defaultSize), size);
    for (const invalid of [null, "", ["15", "15"], 15, "015", "15.0", " 15", "24", "48", "1000"]) {
      assert.equal(catalogPageSize(invalid, choices, defaultSize), null);
    }
  }
});
