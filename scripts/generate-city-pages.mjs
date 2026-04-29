#!/usr/bin/env node
// Generates a single city landing page by transforming public_html/index.html.
// One city per call (no batch mode in this version).
//
// Usage:
//   node scripts/generate-city-pages.mjs --city buckhead
//   node scripts/generate-city-pages.mjs --city buckhead --metro atlanta
//   node scripts/generate-city-pages.mjs --city buckhead --dry-run
//
// Reads:    public_html/index.html, data/service-areas/{metro}.json
// Writes:   public_html/{slug}/index.html
// Refuses:  if the city has no body_copy (run city-content-writer agent first)
// Defers:   homepage update, sitemap regen, .htaccess redirects (separate "finalize" run)

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(__dirname, '..');

// ---- Argument parsing ----------------------------------------------------
const args = process.argv.slice(2);
const flag = (name) => {
  const i = args.indexOf(`--${name}`);
  if (i === -1) return undefined;
  const next = args[i + 1];
  if (!next || next.startsWith('--')) return true; // boolean flag
  return next;
};

const slug = flag('city');
const metro = flag('metro') || 'atlanta';
const dryRun = flag('dry-run') === true;

if (!slug || slug === true) {
  console.error('Usage: node scripts/generate-city-pages.mjs --city <slug> [--metro atlanta] [--dry-run]');
  process.exit(2);
}

// ---- Load data -----------------------------------------------------------
const dataPath = join(repoRoot, 'data', 'service-areas', `${metro}.json`);
if (!existsSync(dataPath)) {
  console.error(`Data file not found: ${dataPath}`);
  process.exit(2);
}
const data = JSON.parse(readFileSync(dataPath, 'utf8'));
const city = (data.cities || []).find((c) => c.slug === slug);
if (!city) {
  console.error(`City "${slug}" not found in ${dataPath}. Available slugs:`);
  console.error('  ' + (data.cities || []).map((c) => c.slug).join(', '));
  process.exit(2);
}
if (!city.body_copy) {
  console.error(`City "${slug}" has no body_copy yet.`);
  console.error(`Run the city-content-writer agent first:`);
  console.error(`  > Use the city-content-writer agent for ${slug}`);
  process.exit(2);
}

// ---- Load source HTML ----------------------------------------------------
const indexPath = join(repoRoot, 'public_html', 'index.html');
if (!existsSync(indexPath)) {
  console.error(`Source HTML not found: ${indexPath}`);
  process.exit(2);
}
let html = readFileSync(indexPath, 'utf8');

// ---- Build substitution values ------------------------------------------
const escapeHtml = (s) =>
  String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const escapeAttr = escapeHtml;

const zipList = city.zips.join(', ');

// Hero tagline reuses the SEO meta_description (single source of truth, validated ≤ 160 chars)
const heroTagline = city.seo.meta_description;

// LocalBusiness + Service schema with areaServed
const schema = {
  '@context': 'https://schema.org',
  '@type': 'HomeAndConstructionBusiness',
  name: 'Universal Service Experts',
  url: `https://universalserviceexperts.com/${city.slug}/`,
  telephone: '(678) 552-2259',
  areaServed: [
    {
      '@type': 'City',
      name: city.display_name,
      containedInPlace: city.parent_municipality !== city.display_name
        ? { '@type': 'City', name: city.parent_municipality }
        : undefined,
    },
    ...city.zips.map((z) => ({ '@type': 'PostalCodeArea', postalCode: z })),
  ],
  address: {
    '@type': 'PostalAddress',
    addressRegion: 'GA',
    addressCountry: 'US',
  },
  description: city.seo.meta_description,
  keywords: city.seo.primary_keywords.join(', '),
};

const schemaScript = `<script type="application/ld+json">${JSON.stringify(schema, null, 2)}</script>`;

// SEO head injection (placed right after <title>)
const headInjection = [
  `<meta name="description" content="${escapeAttr(city.seo.meta_description)}" />`,
  `<meta property="og:title" content="${escapeAttr(city.seo.title_tag)}" />`,
  `<meta property="og:description" content="${escapeAttr(city.seo.meta_description)}" />`,
  `<meta property="og:type" content="website" />`,
  `<meta property="og:url" content="https://universalserviceexperts.com/${city.slug}/" />`,
  schemaScript,
].join('\n');

// Body content section — styled to match the page's brand language:
// yellow pill, Saira blue H2s, light-gray alternating background, centered max-width.
const bc = city.body_copy;
const sectionsHtml = bc.sections
  .map(
    (s) => `
				<h2 style="font-family:Saira,sans-serif; font-size:24px; font-weight:700; color:#0089D1; line-height:1.3; margin:2.25rem 0 0.5rem;">${escapeHtml(s.h2)}</h2>
				<p style="font-family:Roboto,sans-serif; font-size:16px; line-height:1.6; color:#222; margin:0 0 1rem;">${escapeHtml(s.body)}</p>`,
  )
  .join('');

const bodyInjection = `
		<section class="city-seo-content" style="background-color:#F3F3F3; padding:4rem 1.5rem;">
			<div style="max-width:860px; margin:0 auto;">
				<div style="text-align:center; margin-bottom:1.5rem;">
					<span style="display:inline-block; background:#FFA400; color:#ffffff; padding:7px 16px; font-family:Saira,sans-serif; font-size:15px; font-weight:600; letter-spacing:0.1px; text-transform:capitalize;">
						${escapeHtml(city.display_name)} Local Service
					</span>
				</div>
				<p style="text-align:center; max-width:720px; margin:0 auto 1rem; font-family:Roboto,sans-serif; font-size:18px; line-height:1.55; color:#222;">
					${escapeHtml(bc.intro)}
				</p>
${sectionsHtml}
				<p style="margin-top:3rem; padding-top:1.75rem; border-top:1px solid #d0d0d0; font-family:Roboto,sans-serif; font-size:14px; line-height:1.55; color:#73748C; text-align:center;">
					${escapeHtml(bc.areas_served)} <strong style="color:#444;">Service ZIPs: ${escapeHtml(zipList)}.</strong>
				</p>
			</div>
		</section>
`;

// ---- Apply substitutions -------------------------------------------------
const substitutions = [
  // 1. Title tag
  {
    name: 'title',
    from: '<title>Universal Service Experts</title>',
    to: `<title>${escapeHtml(city.seo.title_tag)}</title>\n${headInjection}`,
  },
  // 2. Canonical link
  {
    name: 'canonical',
    from: '<link rel="canonical" href="index.html" />',
    to: `<link rel="canonical" href="https://universalserviceexperts.com/${city.slug}/" />`,
  },
  // 3. H1 plain-text portion (keeps the animated headline hook, replaces the static line)
  {
    name: 'h1-plain',
    from: `<span class="elementor-headline-plain-text elementor-headline-text-wrapper">We're Your One-Stop Solution.</span>`,
    to: `<span class="elementor-headline-plain-text elementor-headline-text-wrapper">${escapeHtml(city.seo.h1)}</span>`,
  },
  // 4. Hero tagline paragraph
  {
    name: 'hero-tagline',
    from: `<p class="p1"><span class="s1">Serving Buckhead, Sandy Springs, Brookhaven, and all of Metro Atlanta with  electrical, HVAC, plumbing, and handyman services. Licensed experts ready to solve any home service need.</span></p>`,
    to: `<p class="p1"><span class="s1">${escapeHtml(heroTagline)}</span></p>`,
  },
  // 5. Secondary "Serving Buckhead to Brookhaven" line in the icon-box section
  {
    name: 'icon-box-tagline',
    from: 'One call handles it all - from electrical emergencies to complete home renovations. Serving Buckhead to Brookhaven and everywhere in between.',
    to: `One call handles it all - from electrical emergencies to complete home renovations. Serving ${escapeHtml(city.display_name)} and surrounding ${escapeHtml(city.parent_county)} County.`,
  },
];

const missing = [];
for (const sub of substitutions) {
  if (!html.includes(sub.from)) {
    missing.push(sub.name);
    continue;
  }
  // Each anchor is documented as appearing exactly once on the homepage; replace first hit only.
  html = html.replace(sub.from, sub.to);
}

// 6. Body content insertion BETWEEN hero (e-parent at line ~156) and the next
// top-level section (e-parent at line ~309). Anchor on the next section's data-id.
const nextSectionAnchor = 'data-id="29faa76a"';
if (!html.includes(nextSectionAnchor)) {
  missing.push('next-section-anchor');
} else {
  const idx = html.indexOf(nextSectionAnchor);
  // Walk back to the opening <div of that section
  const lineStart = html.lastIndexOf('<div', idx);
  if (lineStart === -1) {
    missing.push('next-section-div-start');
  } else {
    html = html.slice(0, lineStart) + bodyInjection + html.slice(lineStart);
  }
}

if (missing.length) {
  console.error(`\n❌ Could not find ${missing.length} anchor(s) in public_html/index.html:`);
  for (const m of missing) console.error(`  • ${m}`);
  console.error('\nThe homepage markup may have changed. Update the anchors in scripts/generate-city-pages.mjs.');
  process.exit(1);
}

// ---- Write output --------------------------------------------------------
const outDir = join(repoRoot, 'public_html', city.slug);
const outPath = join(outDir, 'index.html');

if (dryRun) {
  console.log(`\n[dry-run] Would write ${html.length.toLocaleString()} bytes to:`);
  console.log(`  ${outPath}`);
  console.log(`\nFirst 400 chars of <head>:\n`);
  console.log(html.slice(0, 400));
  console.log(`\nDry run complete. No files written.`);
  process.exit(0);
}

mkdirSync(outDir, { recursive: true });
writeFileSync(outPath, html, 'utf8');

console.log(`\n✅ Wrote ${html.length.toLocaleString()} bytes to:`);
console.log(`   ${outPath}\n`);
console.log(`City:        ${city.display_name} (${city.slug})`);
console.log(`Title tag:   ${city.seo.title_tag}`);
console.log(`Canonical:   https://universalserviceexperts.com/${city.slug}/`);
console.log(`ZIPs:        ${zipList}`);
console.log(`Body words:  intro=${bc.intro.split(/\s+/).length}, sections=${bc.sections.length}, total≈${[bc.intro, ...bc.sections.map(s => s.body), bc.areas_served, bc.closing_cta].join(' ').split(/\s+/).length}`);
console.log(`\nNext: spot-check the page in a browser, validate schema at https://search.google.com/test/rich-results`);
