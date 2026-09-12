import React from 'react';
import {AbsoluteFill, Interactive, interpolate, useCurrentFrame} from 'remotion';
import {AG, FONT} from './brand';

/**
 * A fixed layout budget for the 1280×720 explainers.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * The first cut of these clips was written the way a page is written: content in
 * normal flow, with the closing line absolutely positioned at the bottom. On a
 * page that is fine, because the page is as tall as it needs to be. A video frame
 * is not. Every one of them rendered with the verdict sitting on top of the
 * artwork, and the three-step clip ran off the bottom of the canvas entirely.
 *
 * So the frame is budgeted once, here, in absolute bands that cannot collide:
 *
 *      90 ┌──────────────────────────── eyebrow + headline
 *     265 ├──────────────────────────── STAGE (the artwork)
 *     545 ├──────────────────────────── verdict
 *     645 └──────────────────────────── safe margin
 *
 * Those numbers respect the skill's safe area — key text stays 90px from the
 * sides and 90px from the top and bottom — and the minimums scale from its
 * 1080px reference to this 1280px canvas.
 *
 * Everything a composition draws goes inside `children`, positioned relative to
 * the stage. Nothing is in flow, so nothing can push anything else off the edge.
 */
export const FRAME = {
  pad: 90,
  headerTop: 90,
  stageTop: 265,
  stageHeight: 265,
  verdictTop: 552,
} as const;

export const Frame: React.FC<{
  eyebrow: string;
  headline: string;
  verdict: React.ReactNode;
  verdictFrom: number;
  dark?: boolean;
  children: React.ReactNode;
}> = ({eyebrow, headline, verdict, verdictFrom, dark = false, children}) => {
  const frame = useCurrentFrame();

  return (
    <AbsoluteFill style={{backgroundColor: dark ? AG.ink : AG.page, fontFamily: FONT.body}}>
      <Interactive.Div
        name="Eyebrow"
        style={{
          position: 'absolute',
          left: 90,
          top: 90,
          fontSize: 26,
          fontWeight: 600,
          letterSpacing: 4,
          textTransform: 'uppercase',
          color: dark ? AG.greenLight : AG.green,
          opacity: interpolate(frame, [0, 18], [0, 1], {extrapolateRight: 'clamp'}),
        }}
      >
        {eyebrow}
      </Interactive.Div>

      <Interactive.Div
        name="Headline"
        style={{
          position: 'absolute',
          left: 90,
          right: 90,
          top: 132,
          fontFamily: FONT.display,
          fontSize: 76,
          fontWeight: 700,
          lineHeight: 1.1,
          letterSpacing: -2,
          color: dark ? '#FFFFFF' : AG.ink,
          opacity: interpolate(frame, [6, 26], [0, 1], {extrapolateRight: 'clamp'}),
          transform: `translateY(${interpolate(frame, [6, 30], [22, 0], {extrapolateRight: 'clamp'})}px)`,
        }}
      >
        {headline}
      </Interactive.Div>

      {/* The stage. Absolutely placed, fixed height — artwork cannot grow into
          the verdict band however much of it there is. */}
      <div style={{position: 'absolute', left: 90, right: 90, top: 265, height: 265}}>{children}</div>

      <Interactive.Div
        name="Verdict"
        style={{
          position: 'absolute',
          left: 90,
          right: 90,
          top: 552,
          fontSize: 40,
          lineHeight: 1.4,
          fontWeight: 500,
          color: dark ? 'rgba(255,255,255,.85)' : AG.ink,
          opacity: interpolate(frame, [verdictFrom, verdictFrom + 22], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }),
          transform: `translateY(${interpolate(frame, [verdictFrom, verdictFrom + 26], [16, 0], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          })}px)`,
        }}
      >
        {verdict}
      </Interactive.Div>
    </AbsoluteFill>
  );
};
