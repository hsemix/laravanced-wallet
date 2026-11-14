import { defineConfig } from "astro/config";
import starlight from "@astrojs/starlight";
import fs from "node:fs";

const base = (process.env.SITE_BASE ?? "/laravanced-wallet").replace(/\/$/, "");
const site = process.env.SITE_URL ?? "https://hsemix.github.io";
const groups = JSON.parse(fs.readFileSync(new URL("./src/sidebar.json", import.meta.url), "utf8"));

export default defineConfig({
  site,
  base,
  integrations: [
    starlight({
      title: "Laravanced Wallet",
      description: "A double-entry, multi-account wallet for Laravel: transfer types, fees, authorization, chargebacks, payment requests and scheduled payments.",
      logo: { src: "./src/assets/logo.svg", alt: "Laravanced Wallet" },
      favicon: "/favicon.svg",
      social: [{ icon: "github", label: "GitHub", href: "https://github.com/hsemix/laravanced-wallet" }],
      customCss: ["./src/styles/wallet.css"],
      editLink: { baseUrl: "https://github.com/hsemix/laravanced-wallet/edit/main/" },
      sidebar: groups.map((g) => ({ label: g.label, items: g.slugs.map((slug) => ({ slug })) })),
    }),
  ],
});
