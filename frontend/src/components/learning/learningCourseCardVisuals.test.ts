import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";

const modulePath = "./learningCourseCardVisuals.ts";
const visualModule: typeof import("./learningCourseCardVisuals") = await import(modulePath);
const {
  courseCardImageForAccess,
  courseCardVisualFromHref,
  courseCardVisualRegistryKeys,
} = visualModule;

const allowedCourseKeys = Object.freeze([
  "anatomiya/osteologiya",
  "anatomiya/artrologiya",
  "anatomiya/miologiya",
  "anatomiya/pishchevaritelnaya-sistema",
  "anatomiya/intensiv-po-dyhatelnoj-sisteme",
  "anatomiya/intensiv-po-mochepolovoj-sisteme",
  "anatomiya/ehndokrinnaya-sistema",
  "anatomiya/intensiv-po-serdechno-sosudistoj-sisteme",
  "anatomiya/intensiv-po-tsentralnoj-nervnoj-sisteme",
  "anatomiya/intensiv-po-perifericheskoj-nervnoj-sisteme",
  "anatomiya/immunnaya-i-limfaticheskaya-sistema",
  "gistologiya/tsitologiya",
  "gistologiya/obshchaya-gistologiya-tkani",
  "mikrobiologiya/obshchaya-mikrobiologiya",
  "mikrobiologiya/immunologiya",
  "biohimiya/ehnzimologiya",
  "biohimiya/nutriomika",
  "biohimiya/lipidnyj-obmen",
  "patologicheskaya-anatomiya/intensiv-vnutrikletochnye-nakopleniya",
  "patofiziologiya/patologiya-i-povrezhdenie-kletki",
  "patofiziologiya/patologiya-vospaleniya",
  "patofiziologiya/narusheniya-teplovogo-obmena",
  "propedevtika-vnutrennih-boleznej/propedevtika-pishchevaritelnoj-sistemy",
  "propedevtika-vnutrennih-boleznej/propedevtika-dyhatelnoj-sistemy",
  "propedevtika-vnutrennih-boleznej/propedevtika-mochepolovoj-sistemy",
  "propedevtika-vnutrennih-boleznej/intensiv-ehkg",
  "nevrologiya/dvigatelnye-narusheniya",
  "nevrologiya/onmk-i-pnmk",
  "luchevaya-diagnostika/luchevaya-diagnostika",
  "latinskij-yazyk/latinskij-yazyk",
  "bioorganicheskaya-himiya/bioorganicheskaya-himiya",
  "hirurgiya/obshchaya-hirurgiya",
]);

const excludedCourseKeys = Object.freeze([
  "biohimiya/gormonalnaya-regulyatsiya-metabolizma",
  "biohimiya/intensiv-po-azotistomu-obmenu",
  "biohimiya/intensiv-po-uglevodnomu-i-ehnergeticheskomu-obmenu",
  "biohimiya/osobennosti-metabolizma-v-raznyh-tkanyah",
  "biohimiya/uglevodnyj-i-ehnergeticheskij-obmen-2-0",
  "gistologiya/gistologiya-mochepolovoj-sistemy",
  "gistologiya/gistologiya-serdechno-sosudistoj-sistemy",
  "gistologiya/gistologiya-ehndokrinnoj-sistemy",
  "gistologiya/intensiv-gistologiya-dyhatelnoj-sistemy",
  "gistologiya/intensiv-po-gistologii-pishchevaritelnoj-sistemy",
  "gistologiya/intensiv-po-krovetvoreniyu-i-krovetvornym-organam",
  "gistologiya/nervnaya-sistema-i-organy-chuvstv",
  "mikrobiologiya/kishechnye-infektsii",
  "mikrobiologiya/respiratornye-infektsii",
  "fiziologiya/intensiv-po-fiziologii-tsentralnoj-nervnoj-sistemy",
  "fiziologiya/fiziologiya-vozbudimyh-tkanej",
  "fiziologiya/fiziologiya-pishchevaritelnoj-sistemy",
  "fiziologiya/fiziologiya-serdechno-sosudistoj-sistemy",
  "patologicheskaya-anatomiya/patologiya-mochevydelitelnoj-sistemy",
  "patologicheskaya-anatomiya/patologiya-sistemy-krovi",
  "patofiziologiya/intensiv-patofiziologiya-krovi",
  "patofiziologiya/narusheniya-kislotno-shchelochnogo-ravnovesiya",
  "patofiziologiya/patofiziologiya-immunnoj-sistemy",
  "propedevtika-vnutrennih-boleznej/obshchie-ponyatiya-propedevtiki",
  "farmakologiya/intensiv-preparaty-dlya-razlichnyh-sistem",
  "farmakologiya/preparaty-dlya-lecheniya-serdechno-sosudistyh-zabolevanij",
  "farmakologiya/preparaty-dlya-lecheniya-ehndokrinnyh-zabolevanij",
  "farmakologiya/preparaty-vliyayushchie-na-tsns",
  "farmakologiya/preparaty-dejstvuyushchie-na-pns",
  "farmakologiya/sredstva-vliyayushchie-na-immunitet-npvs-gks",
  "farmakologiya/himioterapevticheskie-preparaty",
]);

function publicAssetPath(src: string): string {
  assert.ok(src.startsWith("/images/"));

  return path.join(process.cwd(), "public", src.slice(1));
}

function learningHref(key: string): string {
  return `/lk/learning/${key}`;
}

function publicHref(key: string): string {
  return `/subjects/${key}`;
}

function avifDimensions(buffer: Buffer): { height: number; width: number } {
  let offset = buffer.indexOf("ispe", 0, "ascii");

  while (offset !== -1) {
    const boxSize = offset >= 4 ? buffer.readUInt32BE(offset - 4) : 0;
    if (boxSize >= 20 && offset + 16 <= buffer.length) {
      return {
        width: buffer.readUInt32BE(offset + 8),
        height: buffer.readUInt32BE(offset + 12),
      };
    }

    offset = buffer.indexOf("ispe", offset + 4, "ascii");
  }

  throw new Error("AVIF image spatial extents (ispe) not found");
}

function readUInt24LE(buffer: Buffer, offset: number): number {
  return buffer[offset] + (buffer[offset + 1] << 8) + (buffer[offset + 2] << 16);
}

function webpDimensions(buffer: Buffer): { height: number; width: number } {
  assert.equal(buffer.toString("ascii", 0, 4), "RIFF");
  assert.equal(buffer.toString("ascii", 8, 12), "WEBP");

  const chunkType = buffer.toString("ascii", 12, 16);
  const frameOffset = 20;

  if (chunkType === "VP8X") {
    return {
      width: readUInt24LE(buffer, frameOffset + 4) + 1,
      height: readUInt24LE(buffer, frameOffset + 7) + 1,
    };
  }

  if (chunkType === "VP8 ") {
    assert.deepEqual([...buffer.subarray(frameOffset + 3, frameOffset + 6)], [0x9d, 0x01, 0x2a]);
    return {
      width: buffer.readUInt16LE(frameOffset + 6) & 0x3fff,
      height: buffer.readUInt16LE(frameOffset + 8) & 0x3fff,
    };
  }

  if (chunkType === "VP8L") {
    assert.equal(buffer[frameOffset], 0x2f);
    const packedDimensions = buffer.readUInt32LE(frameOffset + 1);
    return {
      width: (packedDimensions & 0x3fff) + 1,
      height: ((packedDimensions >>> 14) & 0x3fff) + 1,
    };
  }

  throw new Error(`Unsupported WebP chunk type: ${chunkType}`);
}

function imageDimensions(src: string): { height: number; width: number } {
  const buffer = readFileSync(publicAssetPath(src));
  const dimensions = src.endsWith(".avif") ? avifDimensions(buffer) : webpDimensions(buffer);

  assert.ok(Number.isInteger(dimensions.width) && dimensions.width > 0, `invalid image width for ${src}`);
  assert.ok(Number.isInteger(dimensions.height) && dimensions.height > 0, `invalid image height for ${src}`);

  return dimensions;
}

test("registry contains exactly the 32 approved course visuals", () => {
  assert.equal(allowedCourseKeys.length, 32);
  assert.equal(excludedCourseKeys.length, 31);
  assert.deepEqual([...courseCardVisualRegistryKeys()].sort(), [...allowedCourseKeys].sort());
});

test("approved courses resolve to color and muted AVIF/WebP production pairs", () => {
  for (const key of allowedCourseKeys) {
    const visual = courseCardVisualFromHref(learningHref(key));

    assert.ok(visual, `missing visual mapping for ${key}`);
    assert.equal(visual.image.width, visual.mutedImage.width, key);
    assert.equal(visual.image.height, visual.mutedImage.height, key);

    const expectedSources = [
      visual.image.avifSrc,
      visual.image.webpSrc,
      visual.mutedImage.avifSrc,
      visual.mutedImage.webpSrc,
    ];

    assert.match(visual.image.avifSrc, /-course-02-\d+x\d+\.avif$/, key);
    assert.match(visual.image.webpSrc, /-course-02-\d+x\d+\.webp$/, key);
    assert.match(visual.mutedImage.avifSrc, /-course-muted-02-\d+x\d+\.avif$/, key);
    assert.match(visual.mutedImage.webpSrc, /-course-muted-02-\d+x\d+\.webp$/, key);

    for (const src of expectedSources) {
      assert.ok(existsSync(publicAssetPath(src)), `missing production asset: ${src}`);
      assert.doesNotMatch(src, /-course(?:-muted)?-01-/);
      assert.doesNotMatch(src, /\.png$/);
    }
  }
});

test("declared dimensions match actual production files", () => {
  for (const key of allowedCourseKeys) {
    const visual = courseCardVisualFromHref(learningHref(key));

    assert.ok(visual, `missing visual mapping for ${key}`);

    for (const image of [visual.image, visual.mutedImage]) {
      for (const src of [image.avifSrc, image.webpSrc]) {
        assert.deepEqual(imageDimensions(src), { width: image.width, height: image.height }, src);
      }
    }
  }
});

test("access state selects muted only for no-access courses", () => {
  for (const key of allowedCourseKeys) {
    const visual = courseCardVisualFromHref(learningHref(key));

    assert.ok(visual, `missing visual mapping for ${key}`);
    assert.equal(courseCardImageForAccess(visual, "none"), visual.mutedImage, key);
    assert.equal(courseCardImageForAccess(visual, "partial"), visual.image, key);
    assert.equal(courseCardImageForAccess(visual, "full"), visual.image, key);
  }
});

test("public and authorized URLs resolve the same visual entries", () => {
  for (const key of allowedCourseKeys) {
    assert.equal(courseCardVisualFromHref(publicHref(key)), courseCardVisualFromHref(learningHref(key)), key);
  }
});

test("excluded courses return null and do not expose old draft PNG visuals", () => {
  for (const key of excludedCourseKeys) {
    assert.equal(courseCardVisualFromHref(learningHref(key)), null, key);
    assert.equal(courseCardVisualFromHref(publicHref(key)), null, key);
  }
});

test("module has no draft PNG branch or old immutable revision references", () => {
  const source = readFileSync(new URL("learningCourseCardVisuals.ts", import.meta.url), "utf-8");

  assert.doesNotMatch(source, /courseDraftVisual|LearningCoursePngImageAsset|pngSrc|\/drafts\//);
  assert.doesNotMatch(source, /-course(?:-muted)?-01-/);
});
