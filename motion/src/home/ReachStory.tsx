import React from 'react';
import {
  AbsoluteFill,
  Easing,
  Img,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import {AFRICA, BOX} from '../africa';
import {AG, FONT} from '../brand';
import {ARCS, GEO, ORIGIN, bump} from './ReachMap';

/**
 * The full piece: a signal crosses the continent, the camera falls into Nigeria,
 * and the abstraction turns back into people.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS A SECOND COMPOSITION AND NOT A LONGER ReachMap
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see ReachMap} is a seamless five-second LOOP, and that constraint is the
 * reason it works: every quantity returns to zero at the seam, nothing fades in,
 * nothing accumulates. This piece has an ending — it pushes in, it lands, it
 * holds. Those two contracts cannot live in one component without the loop
 * quietly acquiring a fade, so the geometry and the wave maths are shared
 * (imported from ReachMap) and only the direction differs.
 *
 * The loop stays available for the home page's ambient slot. This one is for
 * anywhere a piece is allowed to finish.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FOUR BEATS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1  SWEEP   0–128    the wavefront crosses Africa, exactly as the loop does
 *   2  PUSH    108–178  the camera falls toward Nigeria; the continent dims to a
 *                       context outline and the opening lockup steps aside
 *   3  POP     156–252  six cards arrive on pins planted in Nigeria, staggered
 *   4  HOLD    262–340  everything settles under one line of type
 *
 * The beats OVERLAP on purpose, and the overlap is the whole craft of the edit.
 * The first cut of this had the push finish at 190 and the first card start at
 * 185 — which looks fine written down and played as a dead second and a half of
 * a country sitting still, because an eased push does almost all its travel in
 * the first third. Cards now begin landing while the camera is still settling,
 * and the sweep is still finishing when the push starts. Nothing in the piece
 * ever waits for something else to be over.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE CARDS ARE ON PINS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Images that simply pop over a map are decoration: they could be any images
 * over any map. A stem down to a dot inside Nigeria makes each one a claim about
 * a place, which is the entire argument of the piece — the index is not a
 * leaderboard floating in the abstract, it is people who are somewhere. The pin
 * is also what earns the zoom: you push in because there is something to see at
 * the bottom of it.
 *
 * The artwork is the site's own illustration set (public/assets/img/illustrations),
 * not stock. It is the honest option available: there is no photography for this
 * platform yet, and inventing photographic "nominees" for a marketing asset would
 * put fabricated people on a page about real ones.
 */

// ── Beat boundaries, in frames at 30fps. One place, so a retime is one edit. ──
const SWEEP_END = 128;
const PUSH_IN = 108;
const PUSH_OUT = 178;
const CARD_START = 156;
const CARD_EVERY = 12; // stagger between card arrivals
const CAPTION_IN = 262;

/** How far the camera falls. Beyond ~3 the country is a shape with no context. */
const ZOOM = 2.35;

/**
 * Six cards, each pinned to a real point inside Nigeria's outline.
 *
 * The anchors are hand-placed in the map's own 1000-unit coordinate space
 * (Nigeria spans roughly x 305–470, y 325–459) and spread so no two pins land on
 * top of each other at full zoom. `dir` is the compass direction the card leans
 * away from the country's centre — it keeps the fan from collapsing inward.
 */
/**
 * `reach` and `scale` vary per card, and that is the whole difference between a
 * fan and a clock face. Six cards at one radius and one size read as a dial —
 * the eye finds the pattern instantly and stops looking. Breaking both by
 * 10–15% keeps it a composition.
 *
 * `illo-ribbon` is deliberately not in this set: it is an infinity mark, and an
 * abstract symbol among five concrete subjects reads as the odd one out. The
 * ballot is the most on-topic object the illustration set has.
 */
const CARDS = [
  {file: 'illos/illo-educator.jpg', ax: 337, ay: 356, dir: -156, delay: 0, reach: 1.0, scale: 1.06},
  {file: 'illos/illo-trophy.jpg', ax: 404, ay: 340, dir: -94, delay: 1, reach: 1.12, scale: 0.9},
  {file: 'illos/illo-medallion.jpg', ax: 452, ay: 372, dir: -32, delay: 2, reach: 0.94, scale: 1.0},
  {file: 'illos/illo-educator2.jpg', ax: 333, ay: 424, dir: 165, delay: 3, reach: 1.06, scale: 1.02},
  {file: 'illos/illo-ballot.png', ax: 392, ay: 438, dir: 94, delay: 4, reach: 1.16, scale: 0.88},
  {file: 'illos/illo-tree.jpg', ax: 449, ay: 428, dir: 30, delay: 5, reach: 0.98, scale: 0.96},
] as const;

const CARD_W = 196;
const CARD_H = 128;
/** How far a card sits from its pin. Enough that the stem reads as a stem. */
const PIN_REACH = 176;

export const ReachStory: React.FC = () => {
  const frame = useCurrentFrame();
  const {fps, width, height} = useVideoConfig();

  // ── Beat 1 · the sweep ───────────────────────────────────────────────────
  //
  // Same wave function as the loop, but driven off an absolute frame rather
  // than a normalised cycle: this one runs once and is over.
  const waveT = interpolate(frame, [0, SWEEP_END], [-0.06, 1.28], {
    extrapolateRight: 'clamp',
  });

  // ── Beat 2 · the push ────────────────────────────────────────────────────
  const push = interpolate(frame, [PUSH_IN, PUSH_OUT], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    // Slow out rather than slow in-out: the camera commits immediately and
    // arrives gently, which is how a real push-in is cut.
    easing: Easing.bezier(0.22, 0.9, 0.24, 1),
  });

  // ── Layout, identical to the loop's so the two can be intercut ───────────
  const mapSize = height * 0.95;
  const mapLeft = width - mapSize - width * 0.028;
  const mapTop = (height - mapSize) / 2;
  const s = mapSize / BOX;

  const zoom = 1 + (ZOOM - 1) * push;

  // The focal point, in the svg element's own pixels.
  const fx = ORIGIN.cx * s;
  const fy = ORIGIN.cy * s;

  // Where Nigeria should end up on screen once the push has landed. Pulled
  // left of centre so the fan of cards has room on the right without the
  // country sliding under them.
  const restX = mapLeft + fx;
  const restY = mapTop + fy;
  const targetX = width * 0.5;
  const targetY = height * 0.435;
  const dx = (targetX - restX) * push;
  const dy = (targetY - restY) * push;

  /** A point in map coordinates → where it actually lands on screen right now. */
  const project = (mx: number, my: number) => ({
    x: mapLeft + dx + fx + (mx * s - fx) * zoom,
    y: mapTop + dy + fy + (my * s - fy) * zoom,
  });

  // Context dims as we arrive, so Nigeria is the only thing still fully drawn.
  const contextDim = interpolate(push, [0.25, 1], [1, 0.28], {extrapolateLeft: 'clamp'});
  const ringR = waveT * Math.max(...GEO.map((g) => g.dist)) * 1.55;
  const ringOn = bump(interpolate(waveT, [-0.06, 1.05], [0, 1], {extrapolateRight: 'clamp'}) / 0.98);

  // The opening lockup steps aside rather than dissolving in place: it slides a
  // little left as it goes, so the exit reads as deliberate.
  const lockup = interpolate(frame, [PUSH_IN, PUSH_IN + 28], [1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.in(Easing.quad),
  });

  return (
    <AbsoluteFill style={{backgroundColor: '#08161A'}}>
      <AbsoluteFill
        style={{
          background: `radial-gradient(90% 80% at ${
            interpolate(push, [0, 1], [62, 50])
          }% 46%, rgba(201,162,75,${interpolate(push, [0, 1], [0.1, 0.16])}) 0%, rgba(8,22,26,0) 64%)`,
        }}
      />

      {/* ── The map ─────────────────────────────────────────────────────────
          Transformed rather than re-projected: scaling the rendered SVG keeps
          every country's geometry identical to the loop's, so the two pieces
          cut together frame-for-frame during the sweep. */}
      <svg
        width={mapSize}
        height={mapSize}
        viewBox={`0 0 ${BOX} ${BOX}`}
        style={{
          position: 'absolute',
          left: mapLeft,
          top: mapTop,
          overflow: 'visible',
          transform: `translate(${dx}px, ${dy}px) scale(${zoom})`,
          transformOrigin: `${fx}px ${fy}px`,
        }}
      >
        <defs>
          {/* A halo on Nigeria's edge only. Cheap, and it does the work a fill
              cannot: it separates the country from the dark ground behind it
              without lightening the interior into fog. */}
          <filter id="ag-nglow" x="-30%" y="-30%" width="160%" height="160%">
            <feDropShadow dx="0" dy="0" stdDeviation="7" floodColor="#FFEFC9" floodOpacity="0.5" />
          </filter>
          <clipPath id="ag-land-story">
            {AFRICA.map((c) => (
              <path key={`clip-${c.id}`} d={c.d} />
            ))}
          </clipPath>
        </defs>

        {ringR > 0 && ringOn > 0.001 ? (
          <g clipPath="url(#ag-land-story)">
            <circle
              cx={ORIGIN.cx}
              cy={ORIGIN.cy}
              r={ringR}
              fill="none"
              stroke="#FFF6DF"
              strokeWidth={2.2 / (s * zoom)}
              opacity={ringOn * 0.95}
              vectorEffect="non-scaling-stroke"
            />
            <circle
              cx={ORIGIN.cx}
              cy={ORIGIN.cy}
              r={ringR * 0.955}
              fill="none"
              stroke={AG.goldLight}
              strokeWidth={11 / (s * zoom)}
              opacity={ringOn * 0.16}
              vectorEffect="non-scaling-stroke"
            />
          </g>
        ) : null}

        {GEO.map((c) => {
          const local = (waveT - c.dNorm * 0.7) / 0.3;
          const lit = bump(local);
          const isOrigin = c.id === ORIGIN.id;
          // Nigeria holds its brightness through the push while everything else
          // recedes — the subject of the shot cannot be dimmed by the shot
          // arriving at it.
          const alpha = isOrigin ? 1 : contextDim;

          return (
            <path
              key={c.id}
              d={c.d}
              // Nigeria has to GAIN presence as the camera arrives, not merely
              // keep it. At the wide shot it is one country among fifty; at the
              // end of the push it is the subject, and a subject rendered at the
              // same weight as its context reads as a grey blob with a white
              // edge — which is exactly how the first pass looked.
              fill={
                isOrigin
                  // GOLD, not cream. Cream at low alpha over dark teal
                  // desaturates to grey, which is what made the subject of the
                  // shot look like a stone.
                  ? `rgba(214,172,96,${0.1 + lit * 0.14 + push * 0.16})`
                  : `rgba(255,235,190,${lit * 0.085 * alpha})`
              }
              stroke={
                isOrigin
                  ? '#FFFFFF'
                  : `rgba(${255 - lit * 12},${239 - lit * 12},${205 + lit * 30},${
                      (0.14 + lit * 0.82) * alpha
                    })`
              }
              strokeWidth={(0.85 + lit * 1.35 + push * 1.1) / (s * zoom)}
              filter={isOrigin && push > 0.02 ? 'url(#ag-nglow)' : undefined}
              strokeLinejoin="round"
              vectorEffect="non-scaling-stroke"
            />
          );
        })}

        {/* Arcs belong to the wide shot only; at 2.35× they leave the frame and
            become stray diagonals. */}
        <g opacity={1 - push}>
          {ARCS.map((a) => {
            const local = (waveT - a.dNorm * 0.7 + 0.16) / 0.42;
            if (local <= 0 || local >= 1) return null;
            const draw = interpolate(local, [0, 0.45], [0, 1], {
              extrapolateRight: 'clamp',
              easing: Easing.out(Easing.cubic),
            });
            const fade = interpolate(local, [0.5, 1], [1, 0], {extrapolateLeft: 'clamp'});
            return (
              <path
                key={a.id}
                d={a.d}
                fill="none"
                stroke={AG.goldLight}
                strokeWidth={1.15 / (s * zoom)}
                strokeLinecap="round"
                strokeDasharray={a.len}
                strokeDashoffset={a.len * (1 - draw)}
                opacity={0.78 * fade}
                vectorEffect="non-scaling-stroke"
              />
            );
          })}
        </g>

        {GEO.map((c) => {
          const lit = bump((waveT - c.dNorm * 0.7) / 0.3);
          if (lit < 0.02) return null;
          const pop = interpolate(lit, [0, 0.35, 1], [0, 1.25, 1], {extrapolateRight: 'clamp'});
          return (
            <circle
              key={`d-${c.id}`}
              cx={c.cx}
              cy={c.cy}
              r={(2.6 * pop) / (s * zoom)}
              fill="#FFF3D6"
              opacity={Math.min(1, lit * 1.3) * (c.id === ORIGIN.id ? 1 : contextDim)}
            />
          );
        })}

        <circle cx={ORIGIN.cx} cy={ORIGIN.cy} r={4.6 / (s * zoom)} fill="#FFFFFF" />
      </svg>

      {/* ── Beat 3 · the cards, in screen space ─────────────────────────────
          Drawn OUTSIDE the transformed SVG on purpose. Inside it they would be
          scaled by the zoom, which softens raster artwork and would make the
          type on a card grow as the camera moves. Their pins are projected
          through the same transform, so they stay locked to the ground while
          staying crisp. */}
      {CARDS.map((c, i) => {
        const at = CARD_START + c.delay * CARD_EVERY;
        const pin = project(c.ax, c.ay);

        // A real spring, not an eased scale. The overshoot is what makes an
        // arrival feel like an object landing rather than a value changing.
        const pop = spring({
          frame: frame - at,
          fps,
          config: {damping: 13, mass: 0.62, stiffness: 118},
          durationInFrames: 34,
        });
        if (pop <= 0.001) return null;

        const rad = (c.dir * Math.PI) / 180;
        const cx = pin.x + Math.cos(rad) * PIN_REACH * c.reach;
        // 0.66, not 0.82: the fan is deliberately WIDER THAN TALL. At 0.82 the
        // lowest card reached into the caption band and sat on the type. A frame
        // is 1100×800 — there is horizontal room to spend and there is not
        // vertical room, so the ellipse follows the frame.
        const cy = pin.y + Math.sin(rad) * PIN_REACH * c.reach * 0.66;

        // Cards travel the last stretch of their own stem as they land.
        const travel = 1 - pop;
        const x = cx - (cx - pin.x) * travel * 0.34;
        const y = cy - (cy - pin.y) * travel * 0.34;

        const tilt = (i % 2 === 0 ? -1 : 1) * (2.4 - pop * 0.9);

        return (
          <React.Fragment key={c.file}>
            {/* Stem and pin, under the card. */}
            <svg
              width={width}
              height={height}
              style={{position: 'absolute', left: 0, top: 0, pointerEvents: 'none'}}
            >
              <line
                x1={pin.x}
                y1={pin.y}
                x2={x}
                y2={y}
                stroke="#FFEFC9"
                strokeWidth={1.4}
                opacity={pop * 0.62}
              />
              <circle cx={pin.x} cy={pin.y} r={13 * pop} fill="#FFEFC9" opacity={pop * 0.16} />
              <circle cx={pin.x} cy={pin.y} r={4.4 * pop} fill="#FFFFFF" opacity={pop} />
            </svg>

            <div
              style={{
                position: 'absolute',
                left: x - (CARD_W * c.scale) / 2,
                top: y - (CARD_H * c.scale) / 2,
                width: CARD_W * c.scale,
                height: CARD_H * c.scale,
                borderRadius: 13,
                overflow: 'hidden',
                background: '#FBF7EC',
                border: '1px solid rgba(255,239,205,0.38)',
                boxShadow: '0 20px 44px rgba(0,0,0,0.5)',
                transform: `scale(${pop}) rotate(${tilt}deg)`,
                opacity: Math.min(1, pop * 1.6),
              }}
            >
              <Img
                src={staticFile(c.file)}
                style={{width: '100%', height: '100%', objectFit: 'cover', display: 'block'}}
              />
            </div>
          </React.Fragment>
        );
      })}

      {/* ── Beat 1's lockup ─────────────────────────────────────────────── */}
      <div
        style={{
          position: 'absolute',
          left: width * 0.055,
          top: height * 0.5,
          transform: `translateY(-50%) translateX(${(1 - lockup) * -26}px)`,
          maxWidth: width * 0.3,
          opacity: lockup,
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
        <div style={{marginTop: 16, width: 46, height: 2, background: AG.goldLight, opacity: 0.85}} />
      </div>

      {/* A scrim under the caption band. Belt and braces: the fan is already
          shaped to clear it, but a card is positioned from a projected map point
          and a future anchor tweak must not be able to put artwork on the type. */}
      <div
        style={{
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: 0,
          height: height * 0.26,
          background: 'linear-gradient(to top, rgba(8,22,26,0.92) 0%, rgba(8,22,26,0) 100%)',
          opacity: interpolate(frame, [CAPTION_IN - 30, CAPTION_IN], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }),
        }}
      />

      {/* ── Beat 4 · the line the whole piece is for ────────────────────── */}
      {(() => {
        const on = interpolate(frame, [CAPTION_IN, CAPTION_IN + 26], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
          easing: Easing.out(Easing.cubic),
        });
        if (on <= 0.001) return null;
        return (
          <div
            style={{
              position: 'absolute',
              left: 0,
              right: 0,
              bottom: height * 0.058,
              textAlign: 'center',
              opacity: on,
              transform: `translateY(${(1 - on) * 14}px)`,
            }}
          >
            <div
              style={{
                fontFamily: FONT.display,
                fontSize: 31,
                color: '#FFFFFF',
                letterSpacing: '-0.01em',
              }}
            >
              Behind every number, a name.
            </div>
            <div
              style={{
                marginTop: 11,
                fontFamily: FONT.mono,
                fontSize: 11.5,
                letterSpacing: '0.24em',
                textTransform: 'uppercase',
                color: 'rgba(232,207,149,0.66)',
              }}
            >
              The Cultural Power Index
            </div>
          </div>
        );
      })()}
    </AbsoluteFill>
  );
};
