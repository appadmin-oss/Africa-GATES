import React from 'react';
import {interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {AG, FONT} from './brand';
import {Frame} from './Frame';

/**
 * CONCEPTUAL — "why a quiet edit is not available to us".
 *
 * For /integrity §08. Hash-chained standings are the most abstract promise on the
 * site and the least believable as prose: "each record carries a fingerprint of
 * the one before it" is a sentence people skim. Watching an edit to block 2 turn
 * blocks 3, 4 and 5 red in sequence is the same claim in a form nobody has to take
 * on trust.
 *
 * The cascade is staggered rather than simultaneous on purpose — the point is that
 * the break PROPAGATES. Everything failing at once would read as a system-wide
 * error rather than as a chain doing exactly what it was built to do.
 */
const BLOCKS = ['#01', '#02', '#03', '#04', '#05'];

export const SealedChain: React.FC = () => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  const tamperAt = 132;

  return (
    <Frame
      dark
      eyebrow="Sealed standings"
      headline="There is no quiet edit."
      verdictFrom={210}
      verdict={
        <>
          One changed number breaks every record after it — and the chain is re-checked{' '}
          <strong style={{color: AG.goldLight}}>every day</strong>.
        </>
      }
    >
      <div style={{display: 'flex', alignItems: 'center', position: 'absolute', top: 26, left: 0}}>
        {BLOCKS.map((label, i) => {
          const appear = spring({frame: frame - (24 + i * 9), fps, config: {damping: 200}});

          // Block 1 is upstream of the edit and stays intact — correct behaviour,
          // and the reason its `broken` is a plain 0 rather than an interpolation
          // from Infinity, which is not a finite input range.
          const brokenFrom = tamperAt + (i - 1) * 11;
          const broken =
            i === 0
              ? 0
              : interpolate(frame, [brokenFrom, brokenFrom + 9], [0, 1], {
                  extrapolateLeft: 'clamp',
                  extrapolateRight: 'clamp',
                });

          const shake = broken > 0 && frame < brokenFrom + 18 ? Math.sin((frame - brokenFrom) * 2.6) * 5 * broken : 0;

          return (
            <React.Fragment key={label}>
              {i > 0 ? (
                <div
                  style={{
                    width: 42,
                    height: 6,
                    backgroundColor: broken > 0.5 ? AG.red : AG.greenLight,
                    opacity: appear * (broken > 0.5 ? 1 : 0.5),
                  }}
                />
              ) : null}
              <div
                style={{
                  width: 152,
                  height: 152,
                  flex: 'none',
                  borderRadius: 20,
                  border: `3px solid ${broken > 0.5 ? AG.red : 'rgba(127,200,124,.45)'}`,
                  backgroundColor: broken > 0.5 ? 'rgba(176,69,63,.18)' : 'rgba(255,255,255,.05)',
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 8,
                  opacity: appear,
                  transform: `scale(${appear}) translateX(${shake}px)`,
                }}
              >
                <span style={{fontFamily: FONT.mono, fontSize: 32, fontWeight: 700, color: '#FFFFFF'}}>{label}</span>
                <span
                  style={{
                    fontFamily: FONT.mono,
                    fontSize: 18,
                    color: broken > 0.5 ? '#F0A9A4' : 'rgba(255,255,255,.5)',
                  }}
                >
                  {broken > 0.5 ? 'BROKEN' : 'a9f3…c2'}
                </span>
              </div>
            </React.Fragment>
          );
        })}
      </div>

      {/* Called out under block 2, inside the stage band. */}
      <div
        style={{
          position: 'absolute',
          left: 196,
          top: 196,
          fontSize: 28,
          fontWeight: 600,
          color: AG.red,
          opacity: interpolate(frame, [tamperAt - 14, tamperAt + 6], [0, 1], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }),
        }}
      >
        ↑ one number edited here
      </div>
    </Frame>
  );
};
