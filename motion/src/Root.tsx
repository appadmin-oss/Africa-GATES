import React from 'react';
import {Composition, staticFile} from 'remotion';
import {loadFont} from '@remotion/fonts';

import {CpiSplit} from './CpiSplit';
import {SealedChain} from './SealedChain';
import {HowVotingWorks} from './HowVotingWorks';
import {WinnerSting, winnerStingSchema} from './WinnerSting';
import {ReachMap} from './home/ReachMap';
import {ReachStory} from './home/ReachStory';

/**
 * The three faces the site already loads — SELF-HOSTED, not fetched.
 *
 * `@remotion/google-fonts` pulls the woff2 from fonts.gstatic.com at render time,
 * which makes every render depend on the network reaching Google and on the
 * rendering browser trusting whatever sits between them. In this environment it
 * failed outright (ERR_CERT_AUTHORITY_INVALID behind the egress proxy) and every
 * frame would have come out in a fallback face.
 *
 * Even where it works it is the wrong trade for a build asset: a clip that renders
 * differently depending on the network is not reproducible. The files are ~100KB
 * in total and live in public/fonts.
 */
loadFont({family: 'Playfair Display', url: staticFile('fonts/playfair-700.woff2'), weight: '700'});
loadFont({family: 'DM Sans', url: staticFile('fonts/dmsans-400.woff2'), weight: '400'});
loadFont({family: 'DM Sans', url: staticFile('fonts/dmsans-500.woff2'), weight: '500'});
loadFont({family: 'DM Sans', url: staticFile('fonts/dmsans-600.woff2'), weight: '600'});
loadFont({family: 'DM Sans', url: staticFile('fonts/dmsans-700.woff2'), weight: '700'});
loadFont({family: 'JetBrains Mono', url: staticFile('fonts/mono-700.woff2'), weight: '700'});

/**
 * Four clips, four different jobs.
 *
 *   CpiSplit         explainer — the claim people doubt most
 *   SealedChain      conceptual — the claim prose cannot carry
 *   HowVotingWorks   instructional — for somebody stuck, not somebody browsing
 *   WinnerSting      celebratory — square, shareable, supporters on it
 *
 * The explainers are 1280×720 so they sit in a content column at full width.
 * The sting is 1080×1080 because its destination is a phone.
 */
export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="CpiSplit"
        component={CpiSplit}
        durationInFrames={240}
        fps={30}
        width={1280}
        height={720}
        defaultProps={{communityPct: 45, judgePct: 55}}
      />
      <Composition
        id="SealedChain"
        component={SealedChain}
        durationInFrames={290}
        fps={30}
        width={1280}
        height={720}
      />
      <Composition
        id="HowVotingWorks"
        component={HowVotingWorks}
        durationInFrames={380}
        fps={30}
        width={1280}
        height={720}
      />
      <Composition
        id="WinnerSting"
        component={WinnerSting}
        durationInFrames={200}
        fps={30}
        width={1080}
        height={1080}
        defaultProps={winnerStingSchema}
      />

      {/* The same opening, allowed to finish: sweep → push into Nigeria → the
          cards land on pins → one line of type. 340 frames = 11.3s. */}
      <Composition
        id="HomeReachStory"
        component={ReachStory}
        durationInFrames={340}
        fps={30}
        width={1100}
        height={800}
      />

      {/* 150 frames at 30fps = exactly 5s, and the loop is seamless — see the
          note in ReachMap. Sized for the home page's ambient slot; the piece is
          resolution-independent, so a bigger render is a number change here. */}
      <Composition
        id="HomeReachMap"
        component={ReachMap}
        durationInFrames={150}
        fps={30}
        width={1100}
        height={800}
      />
    </>
  );
};
