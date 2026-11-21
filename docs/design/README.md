# Design direction

Status: mockups received from the owner (2026-11-16, desktop v8 / mobile v8); working prototype built 2026-11-21 and awaiting the owner's assessment — [prototype](prototype/README.md). Source mockups: [desktop](mockups/desktop/index.html), [mobile](mockups/mobile/index.html) with their READMEs. The HTML/CSS sources are the reference; PNGs are previews.

## Kept from the mockups

- Ubuntu Light 300 / Bold 700 (local files, Ubuntu Font Licence in `mockups/*/assets/UFL.txt`).
- Logo: yellow `#ffcf78` tile, purple `#482341` mark (`assets/icon-yellow.svg`); wave motif `pattern-flow.svg`.
- Teal palette for the employee side, purple for the administrator side; light surfaces, rounded cards, buttons with a left icon, 3 px bottom edge.
- Mobile: tickets as cards, statistics 2 × 2, single-column details, header dropdown navigation, Previous / Next pagination with ≥ 48 px controls. Layout fluid from 360 px.
- Status colours always paired with text.

## Implementation and deviations

The prototype builds directly on the mockup HTML/CSS sources (second version, after the owner rejected a first version that only followed the general direction). Every difference from the mockups, with its reason, is listed in [prototype/README.md](prototype/README.md#differences-from-the-mockups-and-why).

Tokens and mockup styles: [client/src/styles/mockup.css](../../client/src/styles/mockup.css); extra elements: [extras.css](../../client/src/styles/extras.css).
