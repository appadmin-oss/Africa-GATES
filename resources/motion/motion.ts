/**
 * Africa GATES — motion design system (Phase 2 seed).
 *
 * This file is the typed twin of public/assets/css/tokens.motion.css — Framer
 * Motion (running on Preact via preact/compat in Phase 2 islands) consumes
 * these `transition`/`variants` definitions so component-level motion stays
 * consistent with the CSS layer.
 *
 * Phase 0 ships this file inert (not bundled, not imported anywhere). Phase 1
 * stands up Vite + Preact + Framer Motion and starts consuming it.
 *
 * Cross-references:
 *   - public/assets/css/tokens.motion.css — CSS token mirror
 *   - docs/superpowers/specs/2026-06-05-africa-gates-enterprise-upgrade-design.md §5
 */

// Durations (ms)
export const duration = {
  fast: 0.16,
  base: 0.32,
  slow: 0.64,
  glacial: 1.2,
} as const;

// Easings — match the CSS custom properties 1:1.
export const ease = {
  outExpo:  [0.16, 1.00, 0.30, 1.00] as const,
  outQuart: [0.25, 1.00, 0.50, 1.00] as const,
  inOut:    [0.45, 0.00, 0.55, 1.00] as const,
  spring:   [0.34, 1.56, 0.64, 1.00] as const,
};

// Default `transition` configs to spread into Framer Motion <m.*> components.
export const transition = {
  fast: { duration: duration.fast, ease: ease.outExpo },
  base: { duration: duration.base, ease: ease.outExpo },
  slow: { duration: duration.slow, ease: ease.outExpo },
  // For springy interactive feedback (modal pop, layout shifts).
  spring: { type: 'spring', stiffness: 380, damping: 28 } as const,
} as const;

// Shared variants — the vocabulary every island reuses.
export const variants = {
  // Rise + fade-in (entrance reveal).
  rise: {
    hidden: { opacity: 0, y: 16 },
    show:   { opacity: 1, y: 0, transition: transition.base },
  },
  // For container > children stagger sequences.
  stagger: {
    hidden: {},
    show:   { transition: { staggerChildren: 0.06 } },
  },
  fadeIn: {
    hidden: { opacity: 0 },
    show:   { opacity: 1, transition: transition.base },
  },
} as const;
