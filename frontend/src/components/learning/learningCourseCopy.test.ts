import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./learningCourseCopy.ts";
const { distinctCourseShortTitle }: typeof import("./learningCourseCopy") = await import(modulePath);

test("hides a course short title when it duplicates the full title", () => {
  assert.equal(distinctCourseShortTitle("Цитология", "Цитология"), null);
  assert.equal(distinctCourseShortTitle("Цитология", " цитология "), null);
});

test("keeps a course short title when it adds distinct copy", () => {
  assert.equal(distinctCourseShortTitle("Общая гистология: ткани", "Ткани"), "Ткани");
});
