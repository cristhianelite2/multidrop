import React from 'react';
import {Img, staticFile, useCurrentFrame, useVideoConfig} from 'remotion';

type Props = {preset: 'product_presenter' | 'quick_transition'; energy?: number};

/** Low-contrast CSS/SVG accents that move with the frame, never with browser CSS animation. */
export const AnimatedElements: React.FC<Props> = ({preset, energy: energyOverride}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const seconds = frame / fps;
  const energy = energyOverride ?? (preset === 'quick_transition' ? 1 : 0.58);
  const sparkSize = Math.round((preset === 'quick_transition' ? 76 : 56) * (0.7 + energy * 0.3));
  const driftX = Math.sin(seconds * 0.75) * 18 * energy;
  const driftY = Math.cos(seconds * 0.62) * 22 * energy;
  const turn = Math.sin(seconds * 0.42) * 12;

  return (
    <div
      aria-hidden
      style={{position: 'absolute', inset: 0, overflow: 'hidden', pointerEvents: 'none'}}
    >
      <div
        style={{
          position: 'absolute',
          top: 190,
          right: -92,
          width: 270,
          height: 270,
          borderRadius: '50%',
          border: '2px solid rgba(255,255,255,0.58)',
          boxShadow: 'inset 0 0 32px rgba(255,122,0,0.18), 0 0 42px rgba(255,122,0,0.13)',
          opacity: 0.38,
          transform: `translate(${driftX}px, ${driftY}px) scale(${1 + Math.sin(seconds) * 0.045})`,
        }}
      />
      <Img
        src={staticFile('elements/ads/spark.svg')}
        style={{
          position: 'absolute',
          top: 445 + driftY,
          left: 70 + driftX,
          width: sparkSize,
          height: sparkSize,
          opacity: 0.68,
          rotate: `${turn}deg`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          left: -36,
          bottom: 390,
          width: 205,
          height: 205,
          borderRadius: '50%',
          border: '1px solid rgba(255,255,255,0.45)',
          opacity: 0.42,
          transform: `translate(${-driftX}px, ${-driftY}px) scale(${1 + Math.cos(seconds * 0.8) * 0.04})`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          right: 82,
          bottom: 455,
          width: 88,
          height: 88,
          clipPath: 'polygon(50% 0, 61% 37%, 100% 50%, 61% 63%, 50% 100%, 39% 63%, 0 50%, 39% 37%)',
          background: 'linear-gradient(135deg, rgba(255,255,255,0.88), rgba(255,122,0,0.65))',
          opacity: 0.28 + energy * 0.44,
          transform: `rotate(${turn * 1.8}deg) scale(${1 + Math.sin(seconds * 1.2) * 0.08})`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          top: '44%',
          left: 0,
          width: '100%',
          height: 80,
          opacity: 0.12,
          backgroundImage: 'radial-gradient(rgba(255,255,255,0.9) 1.5px, transparent 1.5px)',
          backgroundSize: '24px 24px',
          maskImage: 'linear-gradient(90deg, transparent, #000 16%, #000 84%, transparent)',
          transform: `translateX(${driftX * 0.6}px)`,
        }}
      />
    </div>
  );
};
