import { readdirSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const publicDir = path.join(rootDir, "frontend", "public");

const pairedAssetDirs = [
  {
    label: "home.hero.shadows",
    relativePath: "images/v2/hero-cards/non-mobile/shadows",
    requireSizeInName: true,
  },
  {
    label: "home.hero.non-mobile",
    relativePath: "images/v2/hero-cards/non-mobile",
    requireSizeInName: true,
  },
  {
    label: "home.hero",
    relativePath: "images/v2/hero-cards",
    requireSizeInName: false,
  },
  {
    label: "home.static",
    relativePath: "images/v2/home",
    requireSizeInName: false,
  },
  {
    label: "home.team",
    relativePath: "images/v2/home/team",
    requireSizeInName: true,
  },
  {
    label: "home.subjects",
    relativePath: "images/v2/subjects/generated",
    requireSizeInName: true,
  },
  {
    label: "learning.assessment-results",
    relativePath: "images/v2/lesson-assessment/results",
    requireSizeInName: true,
  },
];

const supportedFormats = new Set([".avif", ".webp"]);
const rawSourceFormats = new Set([".jpg", ".jpeg", ".png", ".heic", ".tif", ".tiff"]);
const sizeSuffixPattern = /-\d+x\d+$/;

function listAssetFiles(relativePath) {
  return readdirSync(path.join(publicDir, relativePath), { withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => entry.name)
    .filter((name) => !name.startsWith("."));
}

function checkDirectory({ label, relativePath, requireSizeInName }) {
  const files = listAssetFiles(relativePath);
  const basenamesByExtension = new Map();
  const errors = [];

  for (const file of files) {
    const extension = path.extname(file).toLowerCase();
    const basename = file.slice(0, -extension.length);

    if (rawSourceFormats.has(extension)) {
      errors.push(`${label}: raw source '${file}' лежит в public/${relativePath}`);
      continue;
    }

    if (!supportedFormats.has(extension)) {
      continue;
    }

    if (requireSizeInName && !sizeSuffixPattern.test(basename)) {
      errors.push(`${label}: '${file}' должен содержать размер в имени, например name-640x800.${extension.slice(1)}`);
    }

    const basenames = basenamesByExtension.get(extension) ?? new Set();
    basenames.add(basename);
    basenamesByExtension.set(extension, basenames);
  }

  const avifBasenames = basenamesByExtension.get(".avif") ?? new Set();
  const webpBasenames = basenamesByExtension.get(".webp") ?? new Set();
  const allBasenames = new Set([...avifBasenames, ...webpBasenames]);

  for (const basename of allBasenames) {
    if (!avifBasenames.has(basename)) {
      errors.push(`${label}: нет AVIF-пары для '${basename}.webp'`);
    }

    if (!webpBasenames.has(basename)) {
      errors.push(`${label}: нет WebP fallback для '${basename}.avif'`);
    }
  }

  return errors;
}

const errors = pairedAssetDirs.flatMap(checkDirectory);

if (errors.length > 0) {
  console.error("[check-image-pipeline] FAIL");
  for (const error of errors) {
    console.error(`- ${error}`);
  }
  process.exit(1);
}

console.log("[check-image-pipeline] PASS: static AVIF/WebP pairs are consistent");
