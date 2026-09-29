import React from 'react';
import {AbsoluteFill, Easing, interpolate, useCurrentFrame, useVideoConfig} from 'remotion';

export type CutTransitionStyle = 'fade' | 'wipe' | 'slide_left' | 'slide_right' | 'zoom_in';

type Props = {style: CutTransitionStyle; index: number};

/** A short graphic bridge for non-contiguous timelines. All movement is frame-based. */
export const CutTransition: React.FC<Props> = ({style, index}) => {
  const frame = useCurrentFrame();
  const {width, height, durationInFrames} = useVideoConfig();
  const end = Math.max(1, durationInFrames - 1);
  const progress = interpolate(frame, [0, end], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.bezier(0.16, 1, 0.3, 1),
  });
  const accent = index % 2 === 0 ? '#FF7A00' : '#FF5A3D';

  if (style === 'fade') {
    return (
      <AbsoluteFill
        style={{
          background: `linear-gradient(135deg, ${accent}, #111827 72%)`,
          opacity: interpolate(progress, [0, 0.5, 1], [0, 0.92, 0]),
          pointerEvents: 'none',
        }}
      />
    );
  }

  if (style === 'zoom_in') {
    const diameter = Math.max(width, height) * 1.9;
    return (
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', pointerEvents: 'none'}}>
        <div
          style={{
            position: 'absolute',
            width: diameter,
            height: diameter,
            borderRadius: '50%',
            border: `${Math.round(width * 0.035)}px solid ${accent}`,
            boxShadow: `0 0 80px ${accent}88, inset 0 0 80px ${accent}66`,
            scale: interpolate(progress, [0, 1], [0.08, 1.4]),
            opacity: interpolate(progress, [0, 0.72, 1], [0.9, 0.82, 0]),
          }}
        />
      </AbsoluteFill>
    );
  }

  const from = style === 'slide_left' ? width * 1.25 : -width * 1.25;
  const to = -from;
  const x = interpolate(progress, [0, 1], [from, to]);

  return (
    <AbsoluteFill style={{overflow: 'hidden', pointerEvents: 'none'}}>
      <div
        style={{
          position: 'absolute',
          top: -height * 0.12,
          left: -width * 0.12,
          width: width * 1.24,
          height: height * 1.24,
          clipPath: 'polygon(0 0, 100% 0, 82% 100%, 0 100%)',
          background: `linear-gradient(115deg, #111827 0%, ${accent} 58%, rgba(255,255,255,0.92) 100%)`,
          translate: `${x}px 0px`,
        }}
      />
      <div
        style={{
          position: 'absolute',
          top: height * 0.48,
          left: 0,
          width: width * 1.4,
          height: 10,
          backgroundColor: 'rgba(255,255,255,0.9)',
          opacity: interpolate(progress, [0, 0.45, 1], [0, 0.85, 0]),
          translate: `${x * 0.84}px 0px`,
        }}
      />
    </AbsoluteFill>
  );
};
