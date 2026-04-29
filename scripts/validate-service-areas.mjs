#!/usr/bin/env node
// Validates a service-areas data file against the schema in data/service-areas/SCHEMA.md.
// Usage: node scripts/validate-service-areas.mjs data/service-areas/atlanta.json
// Exits 0 on success, 1 on validation failure.

import { readFileSync, existsSync } from 'node:fs';
import { basename } from 'node:path';

const REQUIRED_SERVICES = ['electrical', 'hvac', 'plumbing', 'handyman'];
const REQUIRED_CITY_FIELDS = [
  'slug', 'name', 'display_name', 'parent_county', 'parent_municipality',
  'zips', 'notable_landmarks', 'vibe', 'service_relevance',
  'local_ctas', 'content_hooks', 'seo', 'sources',
];
const NULLABLE_NUMERIC_FIELDS = ['population', 'median_home_age', 'homeownership_rate'];

function fail(issues) {
  console.error(`\n❌ Validation failed (${issues.length} issue${issues.length === 1 ? '' : 's'}):\n`);
  for (const issue of issues) console.error(`  • ${issue}`);
  console.error('');
  process.exit(1);
}

function pass(filePath, cityCount) {
  console.log(`\n✅ ${filePath} — ${cityCount} cit${cityCount === 1 ? 'y' : 'ies'} validated.\n`);
  process.exit(0);
}

const filePath = process.argv[2];
if (!filePath) {
  console.error('Usage: node scripts/validate-service-areas.mjs <path/to/metro.json>');
  process.exit(2);
}
if (!existsSync(filePath)) {
  console.error(`File not found: ${filePath}`);
  process.exit(2);
}

let data;
try {
  data = JSON.parse(readFileSync(filePath, 'utf8'));
} catch (err) {
  console.error(`Invalid JSON: ${err.message}`);
  process.exit(2);
}

const issues = [];
const expectedMetro = basename(filePath, '.json');

// Top-level checks
if (data.metro !== expectedMetro) {
  issues.push(`top-level "metro" is "${data.metro}", expected "${expectedMetro}" (matches filename)`);
}
if (!/^\d{4}-\d{2}-\d{2}$/.test(data.version || '')) {
  issues.push(`top-level "version" must be an ISO date (YYYY-MM-DD); got "${data.version}"`);
}
if (!Array.isArray(data.cities)) {
  fail([`top-level "cities" must be an array`]);
}

// Cross-city tracking
const slugSeen = new Map();    // slug -> index
const zipOwner = new Map();    // zip -> slug
const ctaOwner = new Map();    // normalized CTA -> slug

data.cities.forEach((city, idx) => {
  const where = `cities[${idx}]${city.slug ? ` (${city.slug})` : ''}`;

  // Required fields
  for (const field of REQUIRED_CITY_FIELDS) {
    if (!(field in city)) issues.push(`${where}: missing required field "${field}"`);
  }

  // slug
  if (typeof city.slug === 'string') {
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(city.slug)) {
      issues.push(`${where}: slug "${city.slug}" must be lowercase, hyphenated, alphanumeric`);
    }
    if (slugSeen.has(city.slug)) {
      issues.push(`${where}: duplicate slug — also at cities[${slugSeen.get(city.slug)}]`);
    } else {
      slugSeen.set(city.slug, idx);
    }
  }

  // ZIPs
  if (Array.isArray(city.zips)) {
    if (city.zips.length === 0) issues.push(`${where}: zips[] is empty`);
    const localSeen = new Set();
    for (const zip of city.zips) {
      if (typeof zip !== 'string' || !/^\d{5}$/.test(zip)) {
        issues.push(`${where}: zip "${zip}" is not a 5-digit string`);
        continue;
      }
      if (localSeen.has(zip)) issues.push(`${where}: duplicate zip "${zip}" within city`);
      localSeen.add(zip);
      if (zipOwner.has(zip) && zipOwner.get(zip) !== city.slug) {
        issues.push(`${where}: zip "${zip}" already assigned to "${zipOwner.get(zip)}"`);
      }
      zipOwner.set(zip, city.slug);
    }
    // sorted ascending
    const sorted = [...city.zips].sort();
    if (JSON.stringify(sorted) !== JSON.stringify(city.zips)) {
      issues.push(`${where}: zips[] must be sorted ascending`);
    }
  } else if (city.zips !== undefined) {
    issues.push(`${where}: zips must be an array`);
  }

  // Numeric fields (nullable)
  for (const field of NULLABLE_NUMERIC_FIELDS) {
    const val = city[field];
    if (val !== undefined && val !== null && typeof val !== 'number') {
      issues.push(`${where}: ${field} must be a number or null; got ${typeof val}`);
    }
    if (field === 'homeownership_rate' && typeof val === 'number' && (val < 0 || val > 1)) {
      issues.push(`${where}: homeownership_rate must be 0.0–1.0; got ${val}`);
    }
  }

  // notable_landmarks
  if (Array.isArray(city.notable_landmarks)) {
    if (city.notable_landmarks.length < 3 || city.notable_landmarks.length > 5) {
      issues.push(`${where}: notable_landmarks must have 3–5 items; got ${city.notable_landmarks.length}`);
    }
  }

  // vibe
  if (typeof city.vibe === 'string' && city.vibe.length > 140) {
    issues.push(`${where}: vibe is ${city.vibe.length} chars; max 140`);
  }

  // service_relevance
  if (city.service_relevance && typeof city.service_relevance === 'object') {
    for (const svc of REQUIRED_SERVICES) {
      const entry = city.service_relevance[svc];
      if (!entry) {
        issues.push(`${where}: service_relevance.${svc} missing`);
        continue;
      }
      if (!Number.isInteger(entry.score) || entry.score < 1 || entry.score > 5) {
        issues.push(`${where}: service_relevance.${svc}.score must be integer 1–5; got ${entry.score}`);
      }
      if (typeof entry.why !== 'string' || entry.why.length === 0) {
        issues.push(`${where}: service_relevance.${svc}.why must be a non-empty string`);
      }
    }
  }

  // local_ctas
  if (Array.isArray(city.local_ctas)) {
    if (city.local_ctas.length !== 5) {
      issues.push(`${where}: local_ctas must have exactly 5 items; got ${city.local_ctas.length}`);
    }
    const localCtaSeen = new Set();
    for (const cta of city.local_ctas) {
      if (typeof cta !== 'string') {
        issues.push(`${where}: local_ctas entry not a string`);
        continue;
      }
      if (cta.length > 90) issues.push(`${where}: CTA "${cta.slice(0, 50)}..." is ${cta.length} chars; max 90`);
      const norm = cta.trim().toLowerCase();
      if (localCtaSeen.has(norm)) issues.push(`${where}: duplicate CTA within city: "${cta}"`);
      localCtaSeen.add(norm);
      if (ctaOwner.has(norm) && ctaOwner.get(norm) !== city.slug) {
        issues.push(`${where}: CTA "${cta}" duplicates one already in "${ctaOwner.get(norm)}"`);
      }
      ctaOwner.set(norm, city.slug);
      if (/\{CITY\}|\[year\]|\{NEIGHBORHOOD\}/.test(cta)) {
        issues.push(`${where}: CTA contains untemplated placeholder: "${cta}"`);
      }
    }
  }

  // content_hooks
  if (Array.isArray(city.content_hooks)) {
    if (city.content_hooks.length < 2 || city.content_hooks.length > 3) {
      issues.push(`${where}: content_hooks must have 2–3 items; got ${city.content_hooks.length}`);
    }
  }

  // seo
  if (city.seo && typeof city.seo === 'object') {
    if (typeof city.seo.title_tag === 'string' && city.seo.title_tag.length > 60) {
      issues.push(`${where}: seo.title_tag is ${city.seo.title_tag.length} chars; max 60`);
    }
    if (typeof city.seo.meta_description === 'string' && city.seo.meta_description.length > 160) {
      issues.push(`${where}: seo.meta_description is ${city.seo.meta_description.length} chars; max 160`);
    }
    if (!Array.isArray(city.seo.primary_keywords) || city.seo.primary_keywords.length < 3 || city.seo.primary_keywords.length > 6) {
      issues.push(`${where}: seo.primary_keywords must have 3–6 items`);
    }
    for (const k of ['h1', 'title_tag', 'meta_description']) {
      if (typeof city.seo[k] !== 'string' || city.seo[k].length === 0) {
        issues.push(`${where}: seo.${k} must be a non-empty string`);
      }
    }
  }

  // sources
  if (Array.isArray(city.sources)) {
    if (city.sources.length < 2) issues.push(`${where}: sources must have ≥ 2 URLs`);
    for (const src of city.sources) {
      if (typeof src !== 'string' || !/^https:\/\//.test(src)) {
        issues.push(`${where}: source "${src}" must start with https://`);
      }
    }
  }
});

// Cities sorted by slug
const slugs = data.cities.map(c => c.slug);
const sortedSlugs = [...slugs].sort();
if (JSON.stringify(slugs) !== JSON.stringify(sortedSlugs)) {
  issues.push(`cities[] must be sorted by slug ascending`);
}

if (issues.length > 0) fail(issues);
pass(filePath, data.cities.length);
