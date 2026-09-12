import React from 'react';
import {interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {AG, FONT} from './brand';
import {Frame} from './Frame';

/**
 * INSTRUCTIONAL — three steps, one at a time, nothing else moving.
 *
 * A different job from the explainers: nobody watching this is being persuaded,
 * they are stuck. So the motion is calmer — steps arrive in sequence, each
 * completes with a tick, and finished ones stay visible so the viewer can see
 * where they are rather than watching a carousel take their place away.
 *
 * Copy is one line per step, deliberately. The first cut ran two lines each, the
 * cards grew, and the third step fell off the bottom of the canvas — a page can
 * be as tall as it needs to be and a frame cannot.
 */
const STEPS = [
  {n: '1', title: 'Enter your email', body: 'One vote per category. We store only a hash of the address.'},
  {n: '2', title: 'Get a 6-digit code', body: 'Arrives in seconds, and works for this nominee only.'},
  {n: '3', title: 'Your vote counts', body: 'Risk-scored, recorded, and on the tally straight away.'},
];

export const HowVotingWorks: React.FC = () => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  return (
    <Frame
      eyebrow="Free voting"
      headline="Three steps. No account."
      verdictFrom={286}
      verdict={
        <>
          No code? Ask again — it costs nothing and{' '}
          <strong style={{color: AG.green}}>cannot double-count</strong>.
        </>
      }
    >
      {STEPS.map((step, i) => {
        const at = 34 + i * 68;
        const enter = spring({frame: frame - at, fps, config: {damping: 200}});
        // The tick lands a beat after the row settles, so a step reads as
        // "happening" and then "done" rather than arriving pre-completed.
        const done = spring({frame: frame - (at + 40), fps, config: {damping: 14, stiffness: 140}});

        return (
          <div
            key={step.n}
            style={{
              position: 'absolute',
              left: 0,
              right: 0,
              top: i * 92,
              height: 80,
              display: 'flex',
              alignItems: 'center',
              gap: 26,
              padding: '0 30px',
              borderRadius: 18,
              border: `2px solid ${done > 0.4 ? 'rgba(35,123,34,.32)' : 'rgba(16,41,44,.10)'}`,
              backgroundColor: done > 0.4 ? AG.greenWash : AG.surface,
              opacity: enter,
              transform: `translateX(${interpolate(enter, [0, 1], [-40, 0])}px)`,
            }}
          >
            <div
              style={{
                width: 56,
                height: 56,
                flex: 'none',
                borderRadius: 28,
                backgroundColor: done > 0.4 ? AG.green : AG.ink,
                color: '#FFFFFF',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontFamily: FONT.mono,
                fontSize: 28,
                fontWeight: 700,
              }}
            >
              {done > 0.55 ? '✓' : step.n}
            </div>
            <div style={{flex: 1, minWidth: 0}}>
              <div style={{fontSize: 34, fontWeight: 600, color: AG.ink, lineHeight: 1.2}}>{step.title}</div>
              <div style={{fontSize: 24, color: AG.muted, marginTop: 4, lineHeight: 1.3}}>{step.body}</div>
            </div>
          </div>
        );
      })}
    </Frame>
  );
};
