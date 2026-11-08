import { stat } from "node:fs/promises";
import { fileURLToPath } from "node:url";

const maxBytes = 1_048_576;
const repositoryRoot = fileURLToPath(new URL("../", import.meta.url));
const videoPaths = [
  "frontend/public/videos/v2/home/hero-background.mp4",
  "frontend/public/videos/v2/home/hero-background.webm",
  "frontend/public/videos/v2/home/promo-loop.mp4",
  "frontend/public/videos/v2/home/promo-loop.webm",
];

let failed = false;
for (const relativePath of videoPaths) {
  const file = await stat(new URL(relativePath, `file://${repositoryRoot}`));
  console.log(`${relativePath}: ${file.size} bytes`);
  if (file.size >= maxBytes) {
    failed = true;
    console.error(`${relativePath} exceeds the ${maxBytes - 1} byte public media budget.`);
  }
}

if (failed) {
  process.exitCode = 1;
}
