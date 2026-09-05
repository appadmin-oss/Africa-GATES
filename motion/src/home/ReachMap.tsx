import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame, useVideoConfig} from 'remotion';
import {getLength, getPointAtLength} from '@remotion/paths';
import {AFRICA, BOX, BY_ID} from '../africa';
import {AG, FONT} from '../brand';

/**
 * A signal leaving Nigeria and reaching the continent, on a seamless loop.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT MAKES THIS NOT A GENERIC MAP ANIMATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The usual version of this shot fades countries in on a staggered timer, which
 * looks like a slideshow of a map. Here a single wavefront expands from Lagos at
 * a constant speed and each country lights AT THE MOMENT THE FRONT CROSSES ITS
 * CENTROID — the stagger is a consequence of real distance, not a decorative
 * delay. Morocco and Somalia arrive together because they are the same distance
 * away, and you can read that off the screen. Nothing else in the piece has to
 * work hard once the timing is physically true.
 *
 * ── THE LOOP IS EXACT, NOT CROSSFADED ────────────────────────────────────────
 *
 * Every animated quantity is a function of `t = frame / durationInFrames` and is
 * arranged to be ZERO at t = 0 and t = 1: the wave has not been born at the
 * start, and every country's envelope has fully decayed before the end. So frame
 * 149 hands over to frame 0 with nothing in flight and the loop has no seam —
 * no dissolve, no held tail, nothing to hide. The type is deliberately static
 * for the same reason: a caption that fades in cannot loop.
 *
 * ── WHY THE RESTING STATE IS EMPTY ───────────────────────────────────────────
 *
 * Countries flash and return to the hairline rather than staying lit. Cumulative
 * fill would fight the loop (the map would have to blank at the seam) and it
 * reads as "coverage", which is a claim about the business. A pulse reads as
 * "reach", which is what the shot is actually about.
 */

const FPS_CYCLE = 1; // one full sweep per composition

/** Lagos is where this platform's traffic actually originates. */
const ORIGIN_ID = 'NGA';

/** The long hops, drawn as arcs. Chosen for spread, not for importance. */
const ARC_TARGETS = ['MAR', 'EGY', 'ETH', 'KEN', 'ZAF', 'SEN'] as const;

/** Attack is fast and release is slow — a strike, not a throb. */
export const bump = (x: number): number => {
  if (x <= 0 || x >= 1) return 0;
  return x < 0.16
    ? interpolate(x, [0, 0.16], [0, 1], {easing: Easing.out(Easing.cubic)})
    : interpolate(x, [0.16, 1], [1, 0], {easing: Easing.in(Easing.quad)});
};

export const ORIGIN = BY_ID[ORIGIN_ID];
const origin = ORIGIN;

/**
 * Distances precomputed at module scope: this runs once, not 150 times, and the
 * normalisation has to be over the whole set anyway.
 */
export const GEO = (() => {
  const withDist = AFRICA.map((c) => ({
    ...c,
    dist: Math.hypot(c.cx - origin.cx, c.cy - origin.cy),
  }));
  const max = Math.max(...withDist.map((c) => c.dist)) || 1;
  return withDist.map((c) => ({...c, dNorm: c.dist / max}));
})();

/** Quadratic arc bowed away from the straight line, so two arcs never overlap. */
export const arcPath = (x1: number, y1: number, x2: number, y2: number): string => {
  const mx = (x1 + x2) / 2;
  const my = (y1 + y2) / 2;
  const dx = x2 - x1;
  const dy = y2 - y1;
  const len = Math.hypot(dx, dy) || 1;
  // Perpendicular offset scaled to the span: short hops stay nearly straight,
  // long ones bow enough to read as a path rather than a chord.
  const lift = len * 0.22;
  return `M${x1},${y1}Q${mx - (dy / len) * lift},${my + (dx / len) * lift} ${x2},${y2}`;
};

export const ARCS = ARC_TARGETS.map((id) => {
  const c = BY_ID[id];
  const d = arcPath(origin.cx, origin.cy, c.cx, c.cy);
  return {
    id,
    d,
    len: getLength(d),
    dNorm: GEO.find((g) => g.id === id)?.dNorm ?? 1,
  };
});

export const ReachMap: React.FC = () => {
  const frame = useCurrentFrame();
  const {durationInFrames, width, height} = useVideoConfig();
  const t = (frame / durationInFrames) * FPS_CYCLE;

  // The front leaves slightly before t=0 and runs slightly past t=1 so the
  // nearest and furthest countries both get their whole envelope inside the loop.
  const waveT = t * 1.34 - 0.06;

  // ── Layout: type owns the left gutter, the map owns the right. ───────────
  //
  // The map is fitted ENTIRELY inside the canvas with a margin. An earlier pass
  // oversized it to "fill the frame" and clipped Morocco and Tunisia off the top
  // edge — a map of Africa missing the Maghreb is not a stylistic choice, it is
  // a mistake anybody from the continent spots in the first second.
  const mapSize = height * 0.95;
  const mapLeft = width - mapSize - width * 0.028;
  const mapTop = (height - mapSize) / 2;
  const s = mapSize / BOX;

  // A single slow breath over exactly one cycle, so it returns to itself at the
  // seam. Small enough to be felt rather than seen; without it a static outline
  // between pulses reads as a frozen frame.
  const breathe = 1 + Math.sin(t * Math.PI * 2) * 0.006;

  // Ring geometry in map units, so it stays locked to the countries under it.
  // 1.55 carries the front off the edge of the continent rather than stopping it
  // politely at the last centroid.
  const maxR = Math.max(...GEO.map((g) => g.dist));
  const ringR = waveT * maxR * 1.55;
  const ringOn = bump(interpolate(waveT, [-0.06, 1.05], [0, 1], {extrapolateRight: 'clamp'}) / 0.98);

  return (
    <AbsoluteFill style={{backgroundColor: '#08161A'}}>
      {/* Ambient light behind the continent. Static — it must not animate, or the
          loop would need a seam. */}
      <AbsoluteFill
        style={{
          background: `radial-gradient(90% 80% at 62% 46%, rgba(201,162,75,0.10) 0%, rgba(8,22,26,0) 64%)`,
        }}
      />

      <svg
        width={mapSize}
        height={mapSize}
        viewBox={`0 0 ${BOX} ${BOX}`}
        style={{
          position: 'absolute',
          left: mapLeft,
          top: mapTop,
          overflow: 'visible',
          transform: `scale(${breathe})`,
        }}
      >
        <defs>
          {/* The front is clipped to the continent itself.
              ──────────────────────────────────────────────────────────────
              Unclipped, a perfect circle expanding past the coastline is the
              radar sweep everyone has seen, and it competes with the map for
              attention while saying nothing. Clipped to the landmass, the same
              geometry becomes light crossing ground: it disappears in the
              Atlantic, bends around the Gulf of Guinea, and arrives in the Horn
              late — all of which is true, and none of which had to be authored. */}
          <clipPath id="ag-land">
            {AFRICA.map((c) => (
              <path key={`clip-${c.id}`} d={c.d} />
            ))}
          </clipPath>
        </defs>

        {/* ── The wavefront: one hairline, and nothing else.
            ────────────────────────────────────────────────────────────────
            The first pass filled the ring with a soft radial gradient to
            suggest light. At this scale a translucent gold disc over dark teal
            resolves to olive, and the whole frame read as a smudge with a map
            behind it. A single bright 1px arc is both cleaner and more legible:
            you can see exactly where the front is, which is the one thing the
            shot needs you to see. Under the countries, so it passes beneath
            them rather than being drawn over them. */}
        {ringR > 0 && ringOn > 0.001 ? (
          <g clipPath="url(#ag-land)">
            <circle
              cx={origin.cx}
              cy={origin.cy}
              r={ringR}
              fill="none"
              stroke="#FFF6DF"
              strokeWidth={2.2 / s}
              opacity={ringOn * 0.95}
              vectorEffect="non-scaling-stroke"
            />
            {/* A wider, much fainter band just behind the front: enough to give
                it thickness without giving it mass. */}
            <circle
              cx={origin.cx}
              cy={origin.cy}
              r={ringR * 0.955}
              fill="none"
              stroke={AG.goldLight}
              strokeWidth={11 / s}
              opacity={ringOn * 0.16}
              vectorEffect="non-scaling-stroke"
            />
          </g>
        ) : null}

        {/* ── Countries ──────────────────────────────────────────────────── */}
        {GEO.map((c) => {
          // 0.70 of the cycle is the travel time across the continent; 0.30 is
          // how long one country's envelope lasts. They overlap, which is what
          // makes it a sweep instead of a queue.
          const local = (waveT - c.dNorm * 0.7) / 0.3;
          const lit = bump(local);
          const isOrigin = c.id === ORIGIN_ID;

          // Arrival is carried by the STROKE, not by a fill.
          //
          // Filling a country with translucent gold over dark teal produces
          // olive — 51 olive shapes is a swamp, and it buried the geometry that
          // is the whole point of using real borders. A near-white border that
          // flares and decays reads as light hitting an edge, keeps every
          // coastline legible, and lets fifty of them overlap without muddying.
          // The fill that remains is a whisper, present only to stop a lit
          // country from looking hollow.
          return (
            <path
              key={c.id}
              d={c.d}
              fill={
                isOrigin
                  ? `rgba(255,243,214,${0.1 + lit * 0.14})`
                  : `rgba(255,235,190,${lit * 0.085})`
              }
              stroke={
                isOrigin
                  ? '#FFFFFF'
                  : `rgba(${255 - lit * 12},${239 - lit * 12},${205 + lit * 30},${0.14 + lit * 0.82})`
              }
              strokeWidth={(0.85 + lit * 1.35) / s}
              strokeLinejoin="round"
              vectorEffect="non-scaling-stroke"
            />
          );
        })}

        {/* ── Arcs. Revealed by dash offset as the front reaches the target, so
            a hop and its destination light on the same frame. ───────────── */}
        {ARCS.map((a) => {
          const local = (waveT - a.dNorm * 0.7 + 0.16) / 0.42;
          if (local <= 0 || local >= 1) return null;
          const draw = interpolate(local, [0, 0.45], [0, 1], {
            extrapolateRight: 'clamp',
            easing: Easing.out(Easing.cubic),
          });
          const fade = interpolate(local, [0.5, 1], [1, 0], {extrapolateLeft: 'clamp'});
          const head = getPointAtLength(a.d, a.len * draw);

          return (
            <g key={a.id} opacity={fade}>
              <path
                d={a.d}
                fill="none"
                stroke={AG.goldLight}
                strokeWidth={1.15 / s}
                strokeLinecap="round"
                strokeDasharray={a.len}
                strokeDashoffset={a.len * (1 - draw)}
                opacity={0.78}
                vectorEffect="non-scaling-stroke"
              />
              {draw < 1 && head ? (
                <>
                  {/* A soft halo under the head so the travelling dot reads as a
                      light source rather than a speck of dust on the lens. */}
                  <circle cx={head.x} cy={head.y} r={9 / s} fill="#FFEFC9" opacity={0.16} />
                  <circle cx={head.x} cy={head.y} r={3.6 / s} fill="#FFFFFF" />
                </>
              ) : null}
            </g>
          );
        })}

        {/* ── A dot per country, popping on arrival. The part that reads as
            "somebody is there", rather than "this area is coloured". ────── */}
        {GEO.map((c) => {
          const local = (waveT - c.dNorm * 0.7) / 0.3;
          const lit = bump(local);
          if (lit < 0.02) return null;
          const pop = interpolate(lit, [0, 0.35, 1], [0, 1.25, 1], {extrapolateRight: 'clamp'});
          return (
            <circle
              key={`d-${c.id}`}
              cx={c.cx}
              cy={c.cy}
              r={(2.6 * pop) / s}
              fill="#FFF3D6"
              opacity={Math.min(1, lit * 1.3)}
            />
          );
        })}

        {/* ── Origin. Always present: it is where the loop restarts from, so it
            cannot be something that fades. ─────────────────────────────── */}
        <circle cx={origin.cx} cy={origin.cy} r={4.6 / s} fill="#FFFFFF" />
        <circle
          cx={origin.cx}
          cy={origin.cy}
          r={(4.6 + bump(waveT / 0.22) * 16) / s}
          fill="none"
          stroke="#FFFFFF"
          strokeWidth={1.2 / s}
          opacity={0.55 * (1 - bump(waveT / 0.22))}
        />
      </svg>

      {/* ── Type. Static by design; see the class note on the loop. ───────── */}
      <div
        style={{
          position: 'absolute',
          left: width * 0.055,
          top: height * 0.5,
          transform: 'translateY(-50%)',
          maxWidth: width * 0.3,
        }}
      >
        <div
          style={{
            fontFamily: FONT.mono,
            fontSize: 13,
            letterSpacing: '0.22em',
            textTransform: 'uppercase',
            color: 'rgba(232,207,149,0.72)',
            marginBottom: 14,
          }}
        >
          Africa GATES
        </div>
        <div
          style={{
            fontFamily: FONT.display,
            fontSize: 46,
            lineHeight: 1.12,
            color: '#FFFFFF',
            letterSpacing: '-0.012em',
          }}
        >
          One index,
          <br />
          one continent
        </div>
        <div
          style={{
            marginTop: 16,
            width: 46,
            height: 2,
            background: AG.goldLight,
            opacity: 0.85,
          }}
        />
      </div>
    </AbsoluteFill>
  );
};
