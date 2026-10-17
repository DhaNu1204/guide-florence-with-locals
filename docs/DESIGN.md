# Design rules — visual redesign (approved 17 Oct 2026)

UI work follows this file. Phase 9 of `docs/IMPLEMENTATION_PLAN.md` applies it page by page; pages not yet redesigned keep the old Tuscan classes until their step.

## Rules
1. One job per screen. Tours answers "who guides what, and what needs my attention".
2. Colour marks exceptions only. Normal rows are white. Red = needs a guide; amber = rescheduled; blue = non-English language; pale yellow = has a note; grey = cancelled. No green "confirmed" rows.
3. One row grammar. Single booking, group, group member and cancelled booking share the same columns. Booking/group row 56 px (title line + meta line); member and cancelled rows 40 px.
4. Never scroll sideways on desktop. Below 1100 px the page switches to the card list.
5. Show every number once; the actionable one at the top ("1 departure needs a guide").
6. Quiet controls: note, Ask, edit live behind small icon buttons and the ⋯ menu.
7. Strict scales: spacing 4/8/12/16/24/32; type 24/17/14/13/11; weights 400/500/600; tabular numbers for times and guests.
8. Phone is the field tool: first tour visible without scrolling, 44 px touch targets, pinned date/section bar (step 6.23 kept).
9. Build components once and reuse them on every page.

## Tokens
| Token | Value | Tailwind name |
|---|---|---|
| page | #F6F5F2 | `page` |
| surface | #FFFFFF | `surface` |
| line | #E6E3DD | `line` |
| line-soft | #EEECE7 | `line-soft` |
| muted-row | #FBFAF8 | `muted-row` |
| section | #F3F1ED (phone #EFEDE8) | `section` |
| ink | #1D1C1A | `ink` |
| ink-2 | #6B6862 | `ink-2` |
| ink-3 | #8A867F (column labels only) | `ink-3` |
| accent | #B8481F | `accent` |
| accent-hover | #A8401B | `accent-hover` |
| accent-soft | #F6E6DE | `accent-soft` |
| danger | #B42318 | `danger` |
| danger-strong | #912018 | `danger-strong` |
| danger-pill | #FEE4E2 | `danger-pill` |
| danger-strip | #FEF3F2 | `danger-strip` |
| danger-border | #F9D2CD | `danger-border` |
| warn-bg | #FEF0C7 | `warn-bg` |
| warn-ink | #7A2E0E | `warn-ink` |
| info-bg | #E8EEF9 | `info-bg` |
| info-ink | #1F4FA3 | `info-ink` |
| note-bg | #FEF7E0 | `note-bg` |
| note-ink | #7A4A06 | `note-ink` |
| canc-bg | #EFEDE9 | `canc-bg` |
| canc-ink | #5F5C56 | `canc-ink` |

Radius 8 controls, 12 cards, 999 pills. Font Geist 400/500/600, self-hosted (`@fontsource/geist`, no Google Fonts request; the service worker caches the files under `/assets/` like any other asset).

## Base components (`src/components/base/`)
`Button` (primary / secondary / ghost / danger-outline; 36 px desktop, 44 px touch) · `IconButton` (32 px desktop / 44 px touch hit area, `aria-label` required) · `Pill` (need, resched, full, manual, cancelled, lang, note) · `Avatar` (initials, 24 px) · `SectionHeader` · `Toolbar` (wrapping flex row in a surface card).
The folder is `base/`, not `ui/`: the old `src/components/UI/` (Tuscan `Button`, `Card`, `Input`) already exists and Windows paths ignore case. Old imports stay valid until their page is redesigned.
