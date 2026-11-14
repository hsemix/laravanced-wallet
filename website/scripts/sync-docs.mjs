// Builds src/content/docs from the repository's ../docs folder, which stays the single source: it adds the front matter
// Starlight needs, turns relative links into site links (or GitHub links for files that are not part of the site), and
// writes the sidebar groups. Nothing generated is committed (see .gitignore); run it through `npm run dev` / `npm run build`.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, "..", "..");
const docs = path.join(root, "docs");
const out = path.resolve(here, "..", "src", "content", "docs");
const base = (process.env.SITE_BASE ?? "/laravanced-wallet").replace(/\/$/, "");
const repo = process.env.REPO_URL ?? "https://github.com/hsemix/laravanced-wallet";
const branch = process.env.REPO_BRANCH ?? "main";

// Sidebar groups, in reading order (slugs are file names in docs/ without .md). docs/README.md is published as "overview".
const GROUPS = [
  ["Start here", ["overview", "getting-started", "concepts"]],
  ["Ledger setup", ["accounts", "transfer-types", "fees"]],
  ["Payments", ["payments", "authorization", "payment-requests", "chargebacks", "scheduled-payments"]],
  ["Operating it", ["balances-and-history", "events-and-commands", "errors", "testing"]],
  ["Reference", ["reference"]],
];

const slugOf = (rel) => (rel === "README.md" ? "overview" : rel.replace(/\.md$/, ""));
const siteUrl = (rel) => `${base}/${slugOf(rel)}/`;
const github = (abs) => {
  const rel = path.relative(root, abs).split(path.sep).join("/");
  const kind = fs.existsSync(abs) && fs.statSync(abs).isDirectory() ? "tree" : "blob";
  return `${repo}/${kind}/${branch}/${rel}`;
};

const walk = (dir) =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));

function fixLink(url, fromAbs) {
  if (/^([a-z][a-z0-9+.-]*:|#|\/)/i.test(url)) return url;
  const [target, hash = ""] = url.split("#");
  const abs = path.resolve(path.dirname(fromAbs), decodeURIComponent(target));
  const rel = path.relative(docs, abs).split(path.sep).join("/");
  const suffix = hash ? "#" + hash : "";
  if (!rel.startsWith("..") && rel.endsWith(".md") && !rel.includes("/")) return siteUrl(rel) + suffix;
  if (!rel.startsWith("..") && /\.(png|jpe?g|gif|svg|webp)$/i.test(rel)) return `${base}/docs-assets/${rel}`;
  if (abs.startsWith(root) && fs.existsSync(abs)) return github(abs) + suffix;
  return url;
}

function convert(file) {
  const rel = path.relative(docs, file).split(path.sep).join("/");
  const lines = fs.readFileSync(file, "utf8").split("\n");
  let title = path.basename(rel, ".md");
  let fence = false;
  let titled = false;
  const body = [];
  for (const line of lines) {
    if (/^\s*(```|~~~)/.test(line)) fence = !fence;
    if (!fence && !titled && /^# /.test(line)) {
      title = line.slice(2).trim().replace(/`/g, "");
      titled = true; // Starlight renders the title from the front matter
      continue;
    }
    body.push(fence ? line : line.replace(/(\]\()([^)\s]+)(\))/g, (_, a, u, c) => a + fixLink(u, file) + c));
  }
  if (rel === "README.md") title = "Overview";
  const text = body.join("\n").replace(/^\n+/, "");
  const front = `---\ntitle: ${JSON.stringify(title)}\neditUrl: ${JSON.stringify(github(file))}\n---\n\n`;
  fs.writeFileSync(path.join(out, slugOf(rel) + ".md"), front + text);
  return slugOf(rel);
}

fs.rmSync(out, { recursive: true, force: true });
fs.mkdirSync(out, { recursive: true });
const pages = fs.readdirSync(docs).filter((f) => f.endsWith(".md")).map((f) => convert(path.join(docs, f)));

// Images referenced from the docs are served as-is from public/docs-assets.
const assets = path.resolve(here, "..", "public", "docs-assets");
fs.rmSync(assets, { recursive: true, force: true });
for (const f of walk(docs).filter((f) => /\.(png|jpe?g|gif|svg|webp)$/i.test(f))) {
  const dest = path.join(assets, path.relative(docs, f));
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(f, dest);
}

// The home page is written by hand.
fs.copyFileSync(path.resolve(here, "..", "home.mdx"), path.join(out, "index.mdx"));

// Sidebar: the groups above, plus any new page that isn't listed yet (so a new doc is never silently hidden).
const listed = new Set(GROUPS.flatMap(([, slugs]) => slugs));
const unlisted = pages.filter((s) => !listed.has(s)).sort();
const groups = GROUPS.map(([label, slugs]) => ({ label, slugs: slugs.filter((s) => pages.includes(s)) }));
if (unlisted.length) groups.push({ label: "More", slugs: unlisted });
fs.writeFileSync(path.resolve(here, "..", "src", "sidebar.json"), JSON.stringify(groups, null, 2));
console.log(`synced ${pages.length} pages` + (unlisted.length ? ` (not in a sidebar group: ${unlisted.join(", ")})` : ""));
