import { getCollection } from "astro:content";

const entries = await getCollection("collection");
const aboutUs = entries.filter(e => e.id.includes("about-us"));
console.log("About Us entries:");
aboutUs.forEach(e => {
  console.log("  ID:", e.id);
  console.log("    lang:", e.data.lang);
  console.log("    fileType:", e.data.fileType);
  console.log("    has s01_hero:", !!e.data.s01_hero);
  console.log();
});
