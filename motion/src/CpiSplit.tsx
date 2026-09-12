import React from 'react';
import {interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {AG, FONT} from './brand';
import {Frame} from './Frame';

/**
 * EXPLAINER — "what decides the score, and what cannot touch it".
 *
 * For /integrity §01 and §04. The hardest claim this platform makes is that money
 * moves a public tally and not a rank, and on the page it is two columns of prose.
 * Here it is a thing you watch fail: the coin lands on the community bar and
 * bounces off, and nothing about the bar changes while it happens.
 *
 * The weights are PROPS defaulting to the code defaults, so the clip can be
 * re-rendered for a cycle running a different split rather than becoming a
 * published claim nobody can update.
 */
export const CpiSplit: React.FC<{communityPct: number; judgePct: number}> = ({communityPct, judgePct}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  const trackW = 1100;

  // Springs rather than linear tweens: a value that overshoots slightly and
  // settles reads as a measurement landing, where a linear fill reads as a
  // progress bar — the wrong metaphor entirely.
  const growA = spring({frame: frame - 18, fps, config: {damping: 200}});
  const growB = spring({frame: frame - 30, fps, config: {damping: 200}});

  // The coin falls onto the community track (stage-relative y = 74), rebounds,
  // and drops away. Coordinates are stage-relative so it cannot drift out of the
  // band the frame reserved for the artwork.
  const impact = 138;
  const drop = interpolate(frame, [108, impact], [-300, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const rebound = frame > impact ? spring({frame: frame - impact, fps, config: {damping: 9, stiffness: 130}}) : 0;
  const lift = interpolate(rebound, [0, 1], [0, -64]);
  const away = interpolate(frame, [impact + 24, impact + 56], [0, 260], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const spin = interpolate(frame, [108, impact + 56], [0, 480]);
  const fade = interpolate(frame, [impact + 30, impact + 54], [1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  const bars = [
    {label: 'Community votes', pct: communityPct, grow: growA, colour: AG.green, light: AG.greenLight, y: 0},
    {label: 'Judge panel', pct: judgePct, grow: growB, colour: AG.inkSoft, light: '#4E7A7E', y: 130},
  ];

  return (
    <Frame
      eyebrow="The Cultural Power Index"
      headline="Two inputs decide it."
      verdictFrom={176}
      verdict={
        <>
          A contribution moves the <strong style={{color: AG.gold}}>tally</strong>. It never moves the{' '}
          <strong style={{color: AG.green}}>score</strong>.
        </>
      }
    >
      {bars.map((bar) => (
        <div key={bar.label} style={{position: 'absolute', left: 0, top: bar.y, width: trackW}}>
          <div style={{display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 14}}>
            <span style={{fontSize: 40, fontWeight: 600, color: AG.ink}}>{bar.label}</span>
            <span style={{fontFamily: FONT.mono, fontSize: 44, fontWeight: 700, color: bar.colour}}>
              {Math.round(bar.grow * bar.pct)}%
            </span>
          </div>
          <div style={{height: 26, borderRadius: 13, backgroundColor: '#DCE3DC', overflow: 'hidden'}}>
            <div
              style={{
                height: '100%',
                width: (trackW * bar.pct * bar.grow) / 100,
                borderRadius: 13,
                background: `linear-gradient(90deg, ${bar.colour}, ${bar.light})`,
              }}
            />
          </div>
        </div>
      ))}

      {/* The contribution that cannot move them. */}
      <div
        style={{
          position: 'absolute',
          left: 300,
          top: 20,
          transform: `translateY(${drop + lift + away}px) rotate(${spin}deg)`,
          opacity: fade,
        }}
      >
        <div
          style={{
            width: 84,
            height: 84,
            borderRadius: 42,
            background: `linear-gradient(145deg, ${AG.goldLight}, ${AG.gold})`,
            border: `3px solid ${AG.gold}`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontFamily: FONT.mono,
            fontSize: 40,
            fontWeight: 700,
            color: '#5B4A1F',
            boxShadow: '0 16px 30px -12px rgba(91,74,31,.6)',
          }}
        >
          ₦
        </div>
      </div>
    </Frame>
  );
};
