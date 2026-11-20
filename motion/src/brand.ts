/**
 * The palette and type the site already uses, so a rendered clip sitting on a
 * page does not read as a stock asset somebody dropped in.
 *
 * Values are lifted from the site's CSS custom properties and its Google Fonts
 * link (templates/layout/gates.twig): Playfair Display for display, DM Sans for
 * body, JetBrains Mono for figures.
 */
export const AG = {
  ink: '#10292C',
  inkSoft: '#1B3A3E',
  green: '#237B22',
  greenLight: '#7FC87C',
  greenWash: '#EAF6E9',
  gold: '#C9A24B',
  goldLight: '#E8CF95',
  red: '#B0453F',
  page: '#EEF0EC',
  surface: '#FFFFFF',
  muted: '#5A6D6F',
} as const;

export const FONT = {
  display: 'Playfair Display, Georgia, serif',
  body: 'DM Sans, system-ui, sans-serif',
  mono: 'JetBrains Mono, ui-monospace, monospace',
} as const;
