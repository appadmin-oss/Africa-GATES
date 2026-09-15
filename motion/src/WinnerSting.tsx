import React from 'react';
import {AbsoluteFill, Interactive, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {AG, FONT} from './brand';

/**
 * CELEBRATORY — the one clip on this platform that is not explaining anything.
 *
 * Square, because its job is to be shared. Everything else here is an explainer
 * on a page; this is the asset a winner and their supporters post.
 *
 * The design decision worth naming: the SUPPORTERS are on it. A winner card with
 * one name is a certificate, and this platform's whole argument is that a result
 * belongs to the people who made it. So the roll of honour cascades in underneath
 * and gets the second half of the runtime.
 *
 * Name, category and the roll are props so one composition renders every winner
 * rather than being redrawn per person.
 */
export const winnerStingSchema = {
  name: 'Adaeze Okonkwo',
  category: 'Academic Excellence',
  backers: 214,
  roll: ['Ngozi Adeyemi', 'Emeka Obi', 'Aisha Bello', 'Tunde Fashola', 'Amara Nwosu', 'Kofi Mensah', 'Zainab Yusuf'],
};

export const WinnerSting: React.FC<{
  name: string;
  category: string;
  backers: number;
  roll: string[];
}> = ({name, category, backers, roll}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();

  const badge = spring({frame: frame - 10, fps, config: {damping: 12, stiffness: 110}});
  const nameIn = spring({frame: frame - 26, fps, config: {damping: 200}});
  const rule = spring({frame: frame - 96, fps, config: {damping: 200}});

  return (
    <AbsoluteFill
      style={{
        backgroundColor: AG.ink,
        fontFamily: FONT.body,
        padding: 96,
        justifyContent: 'center',
        overflow: 'hidden',
      }}
    >
      {/* A slow gold bloom, so the frame is never completely static. */}
      <div
        style={{
          position: 'absolute',
          top: -260,
          right: -200,
          width: 760,
          height: 760,
          borderRadius: 380,
          background: 'radial-gradient(circle, rgba(201,162,75,.30), transparent 68%)',
          transform: `scale(${interpolate(frame, [0, 180], [0.86, 1.1])})`,
        }}
      />

      <Interactive.Div
        name="Award badge"
        style={{
          alignSelf: 'flex-start',
          display: 'flex',
          alignItems: 'center',
          gap: 14,
          padding: '14px 26px',
          borderRadius: 999,
          border: `2px solid ${AG.gold}`,
          backgroundColor: 'rgba(201,162,75,.16)',
          color: AG.goldLight,
          fontSize: 30,
          fontWeight: 600,
          letterSpacing: 1,
          opacity: badge,
          transform: `scale(${interpolate(badge, [0, 1], [0.82, 1])})`,
        }}
      >
        🏆 Winner · {category}
      </Interactive.Div>

      <Interactive.Div
        name="Winner name"
        style={{
          fontFamily: FONT.display,
          fontSize: 122,
          fontWeight: 700,
          color: '#FFFFFF',
          letterSpacing: -3,
          lineHeight: 1.05,
          marginTop: 34,
          opacity: nameIn,
          transform: `translateY(${interpolate(nameIn, [0, 1], [40, 0])}px)`,
        }}
      >
        {name}
      </Interactive.Div>

      <Interactive.Div
        name="Crowd line"
        style={{
          fontSize: 46,
          lineHeight: 1.45,
          color: 'rgba(255,255,255,.8)',
          marginTop: 26,
          opacity: interpolate(frame, [56, 82], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
        }}
      >
        And <strong style={{color: AG.goldLight}}>{backers}</strong> people put them there.
      </Interactive.Div>

      <div
        style={{
          height: 2,
          backgroundColor: 'rgba(255,255,255,.18)',
          marginTop: 44,
          transform: `scaleX(${rule})`,
          transformOrigin: 'left',
        }}
      />

      <Interactive.Div
        name="Roll heading"
        style={{
          fontSize: 26,
          fontWeight: 700,
          letterSpacing: 4,
          textTransform: 'uppercase',
          color: AG.goldLight,
          marginTop: 30,
          opacity: interpolate(frame, [104, 124], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
        }}
      >
        Roll of honour
      </Interactive.Div>

      {/* Names cascade rather than appearing together: each one is a person, and
          a block of names arriving at once reads as a list instead of a crowd. */}
      <div style={{display: 'flex', flexWrap: 'wrap', gap: 14, marginTop: 24}}>
        {roll.map((person, i) => {
          const pop = spring({frame: frame - (120 + i * 7), fps, config: {damping: 16, stiffness: 130}});
          return (
            <span
              key={person}
              style={{
                padding: '12px 24px',
                borderRadius: 999,
                backgroundColor: 'rgba(255,255,255,.08)',
                border: '1px solid rgba(255,255,255,.16)',
                color: '#FFFFFF',
                fontSize: 32,
                fontWeight: 500,
                opacity: pop,
                transform: `scale(${interpolate(pop, [0, 1], [0.8, 1])})`,
              }}
            >
              {person}
            </span>
          );
        })}
      </div>

      <Interactive.Div
        name="Wordmark"
        style={{
          position: 'absolute',
          left: 96,
          bottom: 72,
          fontSize: 28,
          fontWeight: 600,
          letterSpacing: 3,
          textTransform: 'uppercase',
          color: 'rgba(255,255,255,.5)',
          opacity: interpolate(frame, [150, 172], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}),
        }}
      >
        Africa GATES · Cultural Power Index
      </Interactive.Div>
    </AbsoluteFill>
  );
};
