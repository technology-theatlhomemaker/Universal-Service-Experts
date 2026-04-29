# Service Areas Data — Schema

This directory holds the canonical dataset of cities/neighborhoods Universal Service Experts serves, by metro. The dataset is produced by the `local-market-researcher` agent and consumed by the city-landing-page generator (Phase 2, separate plan).

## Files

- `{metro}.json` — machine-readable, source of truth (e.g. `atlanta.json`)
- `{metro}.md` — human-readable view, **derived** from the JSON (never hand-edit; the agent regenerates it)
- `SCHEMA.md` — this file
- (Sibling: `scripts/validate-service-areas.mjs` — schema + uniqueness validator)

## Top-level shape

```jsonc
{
  "metro": "atlanta",                    // lowercase, slug-style, matches filename
  "version": "2026-04-29",               // ISO date of last update
  "cities": [ /* City objects, sorted by slug */ ]
}
```

## City object

```jsonc
{
  "slug": "buckhead",                    // URL-safe, lowercase, hyphenated, unique within metro
  "name": "Buckhead",                    // canonical name
  "display_name": "Buckhead",            // how it appears in copy (may include accents/punctuation)
  "parent_county": "Fulton",
  "parent_municipality": "Atlanta",      // For unincorporated neighborhoods, the city they sit in.
                                         // For incorporated cities, equal to display_name.
  "zips": ["30305", "30309", "30326", "30327"],  // 5-digit strings, sorted asc, no dupes,
                                                 // no overlap with any other city in this file
  "population": 89000,                   // integer or null
  "median_home_age": 38,                 // integer (years) or null
  "homeownership_rate": 0.62,            // 0.0–1.0 or null

  "notable_landmarks": [                 // 3–5 items, real and recognizable
    "Lenox Square",
    "Phipps Plaza",
    "Atlanta History Center",
    "Chastain Park"
  ],

  "vibe": "Affluent in-town district, mix of pre-war estates and high-rises, HOA-dense",
                                         // ≤ 140 chars, one sentence, plain English

  "service_relevance": {                 // each service scored 1–5 with a one-line "why"
    "electrical": { "score": 5, "why": "Pre-war homes with aging wiring, frequent panel upgrades" },
    "hvac":       { "score": 4, "why": "Older homes need duct retrofits; high-rises need mini-splits" },
    "plumbing":   { "score": 5, "why": "Cast-iron pipes in pre-war homes failing" },
    "handyman":   { "score": 4, "why": "High-end finishes need careful repair" }
  },

  "local_ctas": [                        // exactly 5 strings, ≤ 90 chars each
                                         // Unique within city AND across the entire metro file
    "Same-day electrical service from Lenox to Chastain Park",
    "Pre-war home rewiring specialists serving Buckhead",
    "..."
  ],

  "content_hooks": [                     // 2–3 angle prompts for future page copy
    "Knob-and-tube replacement in pre-1940 Buckhead estates",
    "HOA-compliant exterior work for high-rise condos"
  ],

  "seo": {
    "h1": "Buckhead Electricians, HVAC, Plumbers & Handymen",
    "title_tag": "Buckhead Electrician, HVAC & Plumbing | Universal Service Experts",  // ≤ 60 chars
    "meta_description": "Licensed electrical, HVAC, plumbing, and handyman services in Buckhead (30305, 30309, 30326, 30327). Same-day appointments available.",  // ≤ 160 chars
    "primary_keywords": ["electrician buckhead", "buckhead hvac", "plumber buckhead 30305"]
  },

  "sources": [                           // URLs cited during research, ≥ 2
    "https://tools.usps.com/zip-code-lookup.htm?citybyzipcode",
    "https://data.census.gov/..."
  ],

  "review_notes": null                   // string or null — agent flags here when human review needed
}
```

## Validation rules (enforced by `scripts/validate-service-areas.mjs`)

- `metro` is lowercase, hyphenated, matches the filename stem
- `version` is a valid ISO date
- Every city has all required fields (no missing keys)
- `slug` is unique within the file
- `zips[]` entries are 5-digit strings, no duplicates within a city
- **No ZIP appears in more than one city** (cross-city exclusivity)
- `service_relevance.{service}.score` is an integer 1–5 for each of: electrical, hvac, plumbing, handyman
- Exactly 5 `local_ctas`, each ≤ 90 chars
- All `local_ctas` are unique across the entire metro file (case-insensitive)
- 2–3 `content_hooks`
- `title_tag` ≤ 60 chars, `meta_description` ≤ 160 chars
- `sources[]` has ≥ 2 URLs starting with `https://`
- `population`, `median_home_age`, `homeownership_rate` are numeric or null

The validator exits non-zero on any failure with a list of issues.

## Adding a new metro

1. Pick the metro slug (lowercase, hyphenated): e.g. `charlotte`, `nashville`, `dallas-fort-worth`
2. Run the agent: `> Use the local-market-researcher agent. Metro: charlotte. Top 10 cities.`
3. The agent creates `{metro}.json`, populates it, and regenerates `{metro}.md`
4. Validate: `node scripts/validate-service-areas.mjs data/service-areas/{metro}.json`
5. Human-review the `local_ctas` and `content_hooks` for tone and accuracy
6. Commit

## Editing existing data

- **Never hand-edit `{metro}.md`** — it's regenerated from JSON
- For ZIP corrections or new cities: re-run the agent with a focused scope (`"refresh Sandy Springs ZIPs"` or `"add Decatur and Dunwoody"`)
- For one-off CTA tweaks: edit the JSON directly, run the validator, then have the agent regenerate the MD on the next run

## Why the data is structured this way

- `service_relevance` lets the page generator emphasize the highest-scoring service per city instead of a one-size-fits-all hero
- `content_hooks` aren't finished copy — they're prompts so a human writer (or future content agent) can produce something genuinely unique per page
- `sources` keeps the data auditable; if Census revises a stat, we can re-pull
- `review_notes` is the agent's escape hatch when it can't verify cleanly — better than silent guessing
